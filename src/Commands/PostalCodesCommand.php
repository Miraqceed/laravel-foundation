<?php

namespace Miraqceed\Foundation\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Japan\PostalAddressBook;
use Miraqceed\Foundation\Japan\PostalCodeData;
use Miraqceed\Foundation\Japan\PostalCodesException;
use Miraqceed\Foundation\Japan\ZipEntries;
use Throwable;

/**
 * 郵便番号から住所を引く一覧（Japan\PostalAddressBook が読む）を、日本郵便の公開のデータから作り直す。
 *
 *   php artisan foundation:postal-codes            # 取ってきて作り直す（上3桁ごとの *.json と source.json）。差分を PR にする
 *   php artisan foundation:postal-codes --check    # 置いたデータが、今の日本郵便のデータから今の決まりで作ったものと同じか（書かない）
 *
 * 取るのは日本郵便の決まった2つの URL だけで、ほかへ送らない。外から取ったものを信じない：
 * - リダイレクトに従わない。200 のほかは失敗。時間の上限（全体 60秒。つなぐ 10秒は cURL で送るとき）と取る大きさの上限
 * - ZIP は ZipEntries が、数・名前・方式・展開後の大きさ・CRC・日付を確かめる。番号は7桁の数字だけ。番号が1つもなければ止める
 * - すべて読めてから置き場所の隣の一時の所に書き、名前の付け替えで入れ替える。途中で失敗したら置き場所はそのまま
 * - 番号の数が前の版より 5% を超えて減れば止める（日本郵便の側の欠けたデータで、多くの番号を黙って消さない。--force で通す）
 */
class PostalCodesCommand extends Command
{
    /** 日本郵便の2つの元（設定で替えない） */
    public const SOURCES = [
        'address' => [
            'url' => 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip',
            'entry' => 'utf_ken_all.csv',
        ],
        'office' => [
            'url' => 'https://www.post.japanpost.jp/service/search/zipcode/download/office/zip/jigyosyo.zip',
            'entry' => 'JIGYOSYO.CSV',
        ],
    ];

    /** 取る大きさの上限（今の 2.03MB・0.78MB の約10倍） */
    public const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    /** 前の版より、番号の数がこの割合を超えて減れば止める */
    public const MAX_DROP = 0.05;

    /** 日本郵便に名乗る名前（パッケージの名前だけ。アプリの名前・連絡先を入れない） */
    public const USER_AGENT = 'miraqceed/laravel-foundation';

    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 60;

    private const READ_CHUNK = 1024 * 1024;

    protected $signature = 'foundation:postal-codes
        {--check : 置いたデータが、今の日本郵便のデータから作ったものと同じかを確かめる（書かない）}
        {--force : 番号の数が前の版より大きく減っても書く}';

    protected $description = '郵便番号から住所を引く一覧を、日本郵便の公開のデータから作り直す';

    public function handle(): int
    {
        $book = new PostalAddressBook;

        try {
            $built = $this->build();

            return $this->option('check') ? $this->check($book, $built) : $this->write($book, $built);
        } catch (PostalCodesException $e) {
            $this->error($e->getMessage());
            $this->error('置き場所は書き換えていません');

            return self::FAILURE;
        }
    }

    /**
     * @return array{files: array<string, string>, meta: array<string, mixed>, metaText: string}
     */
    private function build(): array
    {
        $source = [];
        $maps = [];
        foreach (self::SOURCES as $key => ['url' => $url, 'entry' => $entryName]) {
            $zip = $this->fetch($url);
            try {
                $entry = ZipEntries::single($zip, $entryName);
                $map = $key === 'address' ? PostalCodeData::addressEntries($entry['data']) : PostalCodeData::officeEntries($entry['data']);
                // 空のデータで一覧を消さない（前の版がない初回は、5% の減りの止めが効かない）
                if ($map === []) {
                    throw new PostalCodesException('番号が1つもありません');
                }
            } catch (PostalCodesException $e) {
                throw new PostalCodesException("{$url}：{$e->getMessage()}", previous: $e);
            }
            $source[$key] = ['url' => $url, 'zipSha256' => hash('sha256', $zip), 'updated' => $entry['date'], 'codes' => count($map)];
            $maps[] = $map;
        }

        $merged = PostalCodeData::merge(...$maps);
        $files = PostalCodeData::shardFiles($merged);
        // 古さを見るのは、2つの元の更新日のうち古い方
        $updated = min($source['address']['updated'], $source['office']['updated']);
        $meta = [...$source, 'updated' => $updated, 'codes' => count($merged), 'files' => count($files), 'fingerprint' => PostalCodeData::fingerprint($files)];

        return ['files' => $files, 'meta' => $meta, 'metaText' => self::metaText($meta)];
    }

    /**
     * 決まった URL を、リダイレクトに従わず、時間と大きさの上限つきで取る。
     * 'stream' にしない（Guzzle は PHP のストリームで送り、connect_timeout が効かず、timeout が読みの間の待ちだけになる）。
     * cURL では connect_timeout・timeout（全体）が効く。どちらで送っても、進みの知らせ（progress）で大きさと全体の時間を見て止める
     */
    private function fetch(string $url): string
    {
        $deadline = now()->addSeconds(self::TIMEOUT);

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withOptions(['progress' => function (int|float $total, int|float $received) use ($url, $deadline): void {
                    self::guard($url, (int) max($total, $received), $deadline);
                }])
                ->get($url);
        } catch (Throwable $e) {
            // 進みの知らせで止めたときは、その理由を出す
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof PostalCodesException) {
                    throw $cause;
                }
            }
            throw new PostalCodesException("{$url} を取れません（".$e::class.'）', previous: $e);
        }

        if ($response->status() !== 200) {
            throw new PostalCodesException("{$url} を取れません（HTTP {$response->status()}".($response->redirect() ? '。リダイレクトには従いません' : '').'）');
        }

        // 名乗る大きさが上限を超えれば読まない
        $declared = $response->header('Content-Length');
        if (preg_match('/\A[0-9]+\z/', $declared) && (int) $declared > self::MAX_DOWNLOAD_BYTES) {
            throw new PostalCodesException("{$url} が大きすぎます（{$declared} バイト。上限 ".self::MAX_DOWNLOAD_BYTES.' バイト）');
        }

        // 名乗らない・偽って小さく名乗った本文は、読んだ長さで止める
        $body = '';
        try {
            $stream = $response->toPsrResponse()->getBody();
            while (! $stream->eof()) {
                $body .= $stream->read(self::READ_CHUNK);
                self::guard($url, strlen($body), $deadline);
            }
        } catch (PostalCodesException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PostalCodesException("{$url} を読み切れません（".$e::class.'）', previous: $e);
        }

        return $body;
    }

    /** 取った大きさが上限を超えた・取り始めから全体の時間を過ぎたら止める */
    private static function guard(string $url, int $bytes, CarbonInterface $deadline): void
    {
        if ($bytes > self::MAX_DOWNLOAD_BYTES) {
            throw new PostalCodesException("{$url} が大きすぎます（上限 ".self::MAX_DOWNLOAD_BYTES.' バイトを超えた）');
        }
        if (now()->greaterThan($deadline)) {
            throw new PostalCodesException("{$url} を取り終えられません（全体 ".self::TIMEOUT.' 秒を超えた）');
        }
    }

    /**
     * @param  array{files: array<string, string>, meta: array<string, mixed>, metaText: string}  $built
     */
    private function check(PostalAddressBook $book, array $built): int
    {
        $directory = $book->directory();
        $placedMeta = is_file($directory.'/source.json') ? (string) file_get_contents($directory.'/source.json') : null;
        if ($placedMeta === null) {
            $this->error('置き場所に一覧がありません。php artisan foundation:postal-codes で作ってください');

            return self::FAILURE;
        }

        $placed = [];
        foreach (glob($directory.'/[0-9][0-9][0-9].json') ?: [] as $file) {
            $placed[basename($file)] = (string) file_get_contents($file);
        }

        $updated = $built['meta']['updated'];
        if (PostalCodeData::fingerprint($placed) !== $built['meta']['fingerprint']) {
            $problem = '置いた一覧が、今の日本郵便のデータから作ったものと違います';
        } elseif ($placedMeta !== $built['metaText']) {
            // 一覧は同じで、元の ZIP（指紋・日付）だけが違う：日本郵便が中身を変えずに作り直した
            $problem = '一覧は同じですが、元の ZIP（指紋・更新日）が変わりました。source.json だけが変わります';
        } else {
            $this->info("置いた一覧は、今の日本郵便のデータ（更新日 {$updated}）と同じです（{$built['meta']['codes']}番号）");

            return self::SUCCESS;
        }

        $this->error($problem);
        $this->error("php artisan foundation:postal-codes で作り直して PR にしてください（日本郵便の更新日 {$updated}）");

        return self::FAILURE;
    }

    /**
     * @param  array{files: array<string, string>, meta: array<string, mixed>, metaText: string}  $built
     */
    private function write(PostalAddressBook $book, array $built): int
    {
        $directory = $book->directory();
        $this->assertOnlyOurFiles($directory);

        $previous = $this->previousCodes($book);
        $codes = $built['meta']['codes'];
        if ($previous !== null && $codes < $previous * (1 - self::MAX_DROP) && ! $this->option('force')) {
            throw new PostalCodesException("番号の数が前の版より大きく減りました（{$previous} → {$codes}）。日本郵便のデータを確かめ、よければ --force で書いてください");
        }

        $parent = dirname($directory);
        File::ensureDirectoryExists($parent);
        $suffix = bin2hex(random_bytes(6));
        $temporary = $parent.'/.'.basename($directory).'.tmp-'.$suffix;
        $old = $parent.'/.'.basename($directory).'.old-'.$suffix;

        try {
            if (! @mkdir($temporary, 0777)) {
                throw new PostalCodesException('一時の所を作れません');
            }
            foreach ([...$built['files'], 'source.json' => $built['metaText']] as $name => $text) {
                if (@file_put_contents($temporary.'/'.$name, $text) !== strlen($text)) {
                    throw new PostalCodesException('一時の所に書けません');
                }
            }

            // 名前の付け替えで入れ替える（前のものは入れ替えた後に消す）
            $hadPrevious = is_dir($directory);
            if ($hadPrevious && ! @rename($directory, $old)) {
                throw new PostalCodesException('置き場所を入れ替えられません');
            }
            if (! @rename($temporary, $directory)) {
                if ($hadPrevious) {
                    @rename($old, $directory);
                }
                throw new PostalCodesException('置き場所を入れ替えられません');
            }
        } finally {
            if (is_dir($temporary)) {
                File::deleteDirectory($temporary);
            }
        }
        if (is_dir($old)) {
            File::deleteDirectory($old);
        }

        $meta = $built['meta'];
        $this->info("書きました：{$meta['files']}ファイル・{$meta['codes']}番号（住所 {$meta['address']['codes']}・事業所 {$meta['office']['codes']}。日本郵便の更新日 {$meta['updated']}）");

        return self::SUCCESS;
    }

    /** 置き場所は一覧だけのディレクトリ。ほかのファイルがあれば、入れ替えで消さないよう止める（置き場所の書き違いを含む） */
    private function assertOnlyOurFiles(string $directory): void
    {
        if (file_exists($directory) && ! is_dir($directory)) {
            throw new PostalCodesException('置き場所がディレクトリではありません');
        }
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (! preg_match('/\A([0-9]{3}\.json|source\.json)\z/', $name) || ! is_file($directory.'/'.$name)) {
                throw new PostalCodesException('置き場所に、一覧のほかのファイルがあります（'.$name.'）。置き場所は一覧だけのディレクトリにしてください（foundation.japan.postal_codes.path）');
            }
        }
    }

    /** 前の版の番号の数。前の版がない・読めなければ null（比べない） */
    private function previousCodes(PostalAddressBook $book): ?int
    {
        try {
            $codes = $book->source()['codes'] ?? null;
        } catch (PostalCodesException) {
            return null;
        }

        return is_int($codes) ? $codes : null;
    }

    /**
     * source.json の文（2つの空白で字下げ。作り直したときの差分を小さくする）
     *
     * @param  array<string, mixed>  $meta
     */
    private static function metaText(array $meta): string
    {
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^( +)/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
    }
}
