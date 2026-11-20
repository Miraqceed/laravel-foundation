<?php

namespace Miraqceed\Foundation\Tests\Feature\Japan;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Commands\PostalCodesCommand;
use Miraqceed\Foundation\Japan\PostalAddressBook;
use Miraqceed\Foundation\Japan\PostalCodeData;
use Miraqceed\Foundation\Tests\Support\Japan\ZipBuilder;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\Tests\Unit\Japan\PostalCodeDataTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foundation:postal-codes：日本郵便の2つの ZIP（作り物。Http::fake）から一覧を書く・--check。
 * 外から取ったものを信じない：どの失敗でも置き場所の中身は前のままで、一時の所が残らない
 */
class PostalCodesCommandTest extends TestCase
{
    private const ADDRESS_URL = 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip';

    private const OFFICE_URL = 'https://www.post.japanpost.jp/service/search/zipcode/download/office/zip/jigyosyo.zip';

    private string $parent;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parent = sys_get_temp_dir().'/foundation-postal-'.bin2hex(random_bytes(6));
        $this->directory = $this->parent.'/postal-codes';
        File::ensureDirectoryExists($this->parent);
        config(['foundation.japan.postal_codes.path' => $this->directory]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->parent);

        parent::tearDown();
    }

    private static function addressCsv(int $extra = 0): string
    {
        $lines = [
            PostalCodeDataTest::addr('1000001', '東京都', '千代田区', '千代田'),
            PostalCodeDataTest::addr('0600000', '北海道', '架空市中央区', '以下に掲載がない場合'),
        ];
        for ($i = 1; $i <= $extra; $i++) {
            $lines[] = PostalCodeDataTest::addr(sprintf('20000%02d', $i), '埼玉県', '架空市', "架空{$i}丁目");
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private static function officeCsv(): string
    {
        return mb_convert_encoding(PostalCodeDataTest::office('1008799', '東京都', '千代田区', '大手町', '９丁目９－９架空ビル')."\r\n", 'CP932', 'UTF-8');
    }

    private static function addressZip(int $extra = 0): string
    {
        return ZipBuilder::single('utf_ken_all.csv', self::addressCsv($extra), '2026-10-30');
    }

    private static function officeZip(): string
    {
        return ZipBuilder::single('JIGYOSYO.CSV', self::officeCsv(), '2026-10-29');
    }

    /**
     * @param  mixed  $address  住所の ZIP の答え（文字列なら 200 の本文）
     * @param  mixed  $office  事業所の ZIP の答え
     */
    private function fakeJapanPost(mixed $address = null, mixed $office = null): void
    {
        // 文字列は要求のたびに新しい答えにする（同じ答えの本文は1回しか読めない）
        $answer = fn (mixed $given, string $default) => $given === null || is_string($given)
            ? fn () => Http::response($given ?? $default)
            : $given;

        Http::fake([
            self::ADDRESS_URL => $answer($address, self::addressZip()),
            self::OFFICE_URL => $answer($office, self::officeZip()),
        ]);
    }

    /**
     * 本文を少しずつ作る答え（大きな本文を試しの側で持たない）
     *
     * @param  array<string, string>  $headers
     */
    private static function streamed(int $bytes, array $headers = []): \Closure
    {
        return function () use ($bytes, $headers) {
            $left = $bytes;
            $body = new PumpStream(function (int $length) use (&$left) {
                if ($left <= 0) {
                    return false;
                }
                $chunk = str_repeat('a', min($length, $left));
                $left -= strlen($chunk);

                return $chunk;
            });

            return Create::promiseFor(new Psr7Response(200, $headers, $body));
        };
    }

    /** 置き場所の今の中身（名前 → 中身） */
    private function placed(): array
    {
        $files = [];
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }
        ksort($files);

        return $files;
    }

    /** 前の版を置く */
    private function placePrevious(int $codes = 3): array
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/999.json', "{\n\"9990000\":[[\"架空県\",\"架空市\",\"前の版\"]]\n}\n");
        file_put_contents($this->directory.'/source.json', "{\n  \"updated\": \"2026-09-30\",\n  \"codes\": {$codes}\n}\n");

        return $this->placed();
    }

    private function assertNoTemporaryLeft(): void
    {
        $this->assertSame([], glob($this->parent.'/.postal-codes.*') ?: [], '一時の所が残った');
    }

    public function test_日本郵便の2つの_ZIP_から一覧を書き_引ける(): void
    {
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')
            ->expectsOutputToContain('書きました：2ファイル・3番号（住所 2・事業所 1。日本郵便の更新日 2026-10-29）')
            ->assertSuccessful();

        $this->assertSame(['060.json', '100.json', 'source.json'], array_keys($this->placed()));
        $book = app(PostalAddressBook::class);
        $this->assertSame([['prefecture' => '東京都', 'city' => '千代田区', 'street' => '千代田']], $book->lookup('100-0001'));
        $this->assertSame([['prefecture' => '東京都', 'city' => '千代田区', 'street' => '大手町9丁目9-9']], $book->lookup('100-8799'));
        $this->assertSame([['prefecture' => '北海道', 'city' => '架空市中央区', 'street' => '']], $book->lookup('060-0000'));
        $this->assertNoTemporaryLeft();
    }

    public function test_source_json_に元の指紋・更新日・数とデータの指紋を書く(): void
    {
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')->assertSuccessful();

        $placed = $this->placed();
        $source = json_decode($placed['source.json'], true);
        $this->assertSame([
            'url' => self::ADDRESS_URL, 'zipSha256' => hash('sha256', self::addressZip()), 'updated' => '2026-10-30', 'codes' => 2,
        ], $source['address']);
        $this->assertSame([
            'url' => self::OFFICE_URL, 'zipSha256' => hash('sha256', self::officeZip()), 'updated' => '2026-10-29', 'codes' => 1,
        ], $source['office']);
        // 古さを見るのは、2つの元の更新日のうち古い方
        $this->assertSame('2026-10-29', $source['updated']);
        $this->assertSame(3, $source['codes']);
        $this->assertSame(2, $source['files']);
        unset($placed['source.json']);
        $this->assertSame(PostalCodeData::fingerprint($placed), $source['fingerprint']);
        // 2つの空白で字下げし、/ を逃がさない
        $this->assertStringContainsString("\n  \"address\": {\n    \"url\": \"https://www.post.japanpost.jp/", $this->placed()['source.json']);
    }

    public function test_取るのは決まった2つの_URL_だけで_名乗るのはパッケージの名前だけ(): void
    {
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')->assertSuccessful();

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $this->assertSame([self::ADDRESS_URL, self::OFFICE_URL], $sent->map(fn (Request $r) => $r->url())->all());
        $sent->each(fn (Request $r) => $this->assertSame(['miraqceed/laravel-foundation'], $r->header('User-Agent')));
        $sent->each(fn (Request $r) => $this->assertSame('GET', $r->method()));
    }

    public function test_うまくいけば置き場所が入れ替わり_今はない上3桁のファイルが残らない(): void
    {
        $this->placePrevious();
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')->assertSuccessful();

        $this->assertSame(['060.json', '100.json', 'source.json'], array_keys($this->placed()));
        $this->assertNoTemporaryLeft();
    }

    public function test_置き場所の親がなければ作る(): void
    {
        $this->directory = $this->parent.'/deep/postal-codes';
        config(['foundation.japan.postal_codes.path' => $this->directory]);
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')->assertSuccessful();

        $this->assertFileExists($this->directory.'/source.json');
    }

    /** @return array<string, array{\Closure(): array{mixed, mixed}, string}> */
    public static function refusedSources(): array
    {
        $good = fn () => self::addressZip();

        return [
            'リダイレクト' => [fn () => [Http::response('', 302, ['Location' => 'https://example.com/other.zip']), null], 'HTTP 302'],
            '200 でない答え' => [fn () => [Http::response('', 503), null], 'HTTP 503'],
            '事業所の 404' => [fn () => [null, Http::response('', 404)], 'jigyosyo.zip を取れません（HTTP 404'],
            'つながらない' => [fn () => [Http::failedConnection(), null], 'ConnectionException'],
            '上限を超える Content-Length' => [fn () => [Http::response($good(), 200, ['Content-Length' => (string) (20 * 1024 * 1024 + 1)]), null], '大きすぎます'],
            '名乗らずに上限を超える本文' => [fn () => [self::streamed(20 * 1024 * 1024 + 1), null], '大きすぎます'],
            '小さく名乗って上限を超える本文' => [fn () => [self::streamed(20 * 1024 * 1024 + 1, ['Content-Length' => '100']), null], '大きすぎます'],
            '中のファイルが2つ' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv())->add('extra.csv', 'x')->build(), null], '1つではありません'],
            '名前の違う CSV' => [fn () => [ZipBuilder::single('KEN_ALL.CSV', self::addressCsv()), null], '名前'],
            '道を外へ向ける名前' => [fn () => [ZipBuilder::single('../utf_ken_all.csv', self::addressCsv()), null], '名前'],
            '名乗る大きさが上限を超える ZIP' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv(), size: 200 * 1024 * 1024 + 1)->build(), null], '大きすぎます'],
            '名乗りより大きく広がる中身' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv().str_repeat("\r\n", 100_000), size: strlen(self::addressCsv()))->build(), null], '解けません'],
            'CRC の違い' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv(), crc: 1)->build(), null], 'CRC'],
            '暗号' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv(), flags: 1)->build(), null], '暗号'],
            '知らない圧縮の方式' => [fn () => [ZipBuilder::make()->add('utf_ken_all.csv', self::addressCsv(), method: 14)->build(), null], '方式'],
            '壊れた ZIP（終わりの印がない）' => [fn () => [substr(self::addressZip(), 0, -22), null], '終わりの印'],
            '列の数が違う' => [fn () => [ZipBuilder::single('utf_ken_all.csv', "13101,\"100  \",\"1000001\"\r\n"), null], '列の数'],
            '事業所の読めない文字' => [fn () => [null, ZipBuilder::single('JIGYOSYO.CSV', "13101,\"\x81\"")], 'Shift_JIS'],
        ];
    }

    /**
     * @param  \Closure(): array{mixed, mixed}  $responses
     */
    #[DataProvider('refusedSources')]
    public function test_信じられない答えでは止まり_置き場所は前のまま(\Closure $responses, string $reason): void
    {
        $before = $this->placePrevious();
        [$address, $office] = $responses();
        $this->fakeJapanPost($address, $office);

        $this->artisan('foundation:postal-codes')
            ->expectsOutputToContain($reason)
            ->expectsOutputToContain('置き場所は書き換えていません')
            ->assertFailed();

        $this->assertSame($before, $this->placed());
        $this->assertNoTemporaryLeft();
    }

    public function test_失敗の文に_取った_URL_と理由を書き_中身は書かない(): void
    {
        $this->placePrevious();
        $this->fakeJapanPost(ZipBuilder::single('utf_ken_all.csv', "13101,\"秘密の行\"\r\n"));

        $this->artisan('foundation:postal-codes')
            ->expectsOutputToContain(self::ADDRESS_URL.'：1行目の列の数が 2 です（15 のはず）')
            ->doesntExpectOutputToContain('秘密の行')
            ->assertFailed();
    }

    public function test_番号の数が前の版より_5_パーセントを超えて減れば止まり_force_で通る(): void
    {
        // 新しい一覧は 19 番号（住所 18・事業所 1）
        $this->fakeJapanPost(self::addressZip(extra: 16));

        // 20 → 19 はちょうど 5% の減りなので通る
        $this->placePrevious(codes: 20);
        $this->artisan('foundation:postal-codes')->assertSuccessful();

        // 21 → 19 は 5% を超える
        $before = $this->placePrevious(codes: 21);
        $this->artisan('foundation:postal-codes')
            ->expectsOutputToContain('番号の数が前の版より大きく減りました（21 → 19）')
            ->assertFailed();
        $this->assertSame($before, $this->placed());
        $this->assertNoTemporaryLeft();

        $this->artisan('foundation:postal-codes', ['--force' => true])->assertSuccessful();
        $this->assertSame(19, app(PostalAddressBook::class)->source()['codes']);
    }

    public function test_置き場所に一覧のほかのファイルがあれば止まる(): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/README.md', '手書き');
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes')
            ->expectsOutputToContain('一覧のほかのファイルがあります（README.md）')
            ->assertFailed();

        $this->assertSame(['README.md' => '手書き'], $this->placed());
        $this->assertNoTemporaryLeft();
    }

    public function test_check_は作り直したものと同じなら成功し_書かない(): void
    {
        $this->fakeJapanPost();
        $this->artisan('foundation:postal-codes')->assertSuccessful();
        $before = $this->placed();

        $this->artisan('foundation:postal-codes', ['--check' => true])
            ->expectsOutputToContain('置いた一覧は、今の日本郵便のデータ（更新日 2026-10-29）と同じです（3番号）')
            ->assertSuccessful();

        $this->assertSame($before, $this->placed());
    }

    public function test_check_は一覧が違えば失敗し_書かない(): void
    {
        $this->fakeJapanPost();
        $this->artisan('foundation:postal-codes')->assertSuccessful();
        file_put_contents($this->directory.'/100.json', "{\n\"1000001\":[[\"東京都\",\"千代田区\",\"手で直した\"]]\n}\n");
        $before = $this->placed();

        $this->artisan('foundation:postal-codes', ['--check' => true])
            ->expectsOutputToContain('置いた一覧が、今の日本郵便のデータから作ったものと違います')
            ->assertFailed();

        $this->assertSame($before, $this->placed());
        $this->assertNoTemporaryLeft();
    }

    public function test_check_は元の_ZIP_だけが変われば_それを言って失敗する(): void
    {
        $addressZip = self::addressZip();
        Http::fake([
            self::ADDRESS_URL => function () use (&$addressZip) {
                return Http::response($addressZip);
            },
            self::OFFICE_URL => fn () => Http::response(self::officeZip()),
        ]);
        $this->artisan('foundation:postal-codes')->assertSuccessful();
        $before = $this->placed();

        // 中身は同じで、ZIP の日付だけが違う
        $addressZip = ZipBuilder::single('utf_ken_all.csv', self::addressCsv(), '2026-11-30');
        $this->artisan('foundation:postal-codes', ['--check' => true])
            ->expectsOutputToContain('一覧は同じですが、元の ZIP（指紋・更新日）が変わりました')
            ->assertFailed();

        $this->assertSame($before, $this->placed());
    }

    public function test_check_は置き場所がなければ失敗する(): void
    {
        $this->fakeJapanPost();

        $this->artisan('foundation:postal-codes', ['--check' => true])
            ->expectsOutputToContain('置き場所に一覧がありません')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_コマンドの上限は設計のとおり(): void
    {
        $this->assertSame(20 * 1024 * 1024, PostalCodesCommand::MAX_DOWNLOAD_BYTES);
        $this->assertSame(0.05, PostalCodesCommand::MAX_DROP);
        $this->assertSame([self::ADDRESS_URL, self::OFFICE_URL], array_column(PostalCodesCommand::SOURCES, 'url'));
    }
}
