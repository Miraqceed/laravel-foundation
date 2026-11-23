<?php

namespace Miraqceed\Foundation\DataFiles;

use Carbon\CarbonImmutable;
use Miraqceed\Foundation\Failures\FailureReporter;
use Throwable;

/**
 * 1つの種類の更新の流れ（ロックの中）：
 *   取る → 同じディレクトリの一時のファイルに書く（gzip なら流しながら解く）→ 大きさの下限・上限 → verify() → 版を比べる
 *   → 減りの止め → fsync → rename
 * 版の比べと rename はロックの中で行う（重なった実行が、古い版を後から入れない）。
 * どの失敗でも、置き場所は前のまま・一時のファイルは残らない。
 *
 * @internal
 */
final class Refresher
{
    public function __construct(
        private readonly Fetcher $fetcher = new Fetcher,
        private readonly FailureReporter $reporter = new FailureReporter,
    ) {}

    /** @throws DataFileException */
    public function refresh(DataSource $source, bool $force = false): RefreshResult
    {
        $name = $source->name();
        $limits = $source->limits();
        $store = Store::for($name, $this->reporter);

        if (! $store->lock()) {
            return new RefreshResult(RefreshStatus::Busy, "{$name}：ほかの実行が動いています。何もしません");
        }

        /** @var list<string> $temporaries */
        $temporaries = [];
        try {
            $store->removeLeftovers();
            $installed = $this->installed($source, $store);
            $notYet = false;

            foreach ($source->downloads(CarbonImmutable::now('UTC')) as $download) {
                if (! $download instanceof Download) {
                    throw new DataFileException("{$name}：downloads() は Download を返してください");
                }

                $temporaries[] = $raw = $store->temporaryPath();
                if ($this->fetcher->fetch($name, $download, $raw, $limits->maxDownloadBytes) === 404) {
                    $notYet = true;

                    continue;
                }

                $file = $raw;
                if ($download->isGzip()) {
                    $temporaries[] = $file = $store->temporaryPath();
                    Gunzip::file($name, $raw, $file, $limits->maxBytes);
                    @unlink($raw);
                }

                return $this->install($source, $limits, $store, $file, $installed, $force);
            }

            if ($notYet && $installed !== null) {
                $store->markChecked();

                return new RefreshResult(RefreshStatus::NotYet, "{$name}：新しい版はまだ出ていません。入っているものをそのまま使います", $installed['version']);
            }

            throw new DataFileException($notYet ? "{$name}：まだ出ていません（入っているものもありません）" : "{$name}：取り方がありません");
        } catch (DataFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            // ディスクがいっぱいなど：種類の名前と例外の種類だけ（文に道・データが入りうる）
            throw new DataFileException("{$name}：入れ替えに失敗しました（".$e::class.'）');
        } finally {
            foreach ($temporaries as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            $store->unlock();
        }
    }

    /**
     * 入っているファイルの版と大きさ。なければ・verify() で読めなければ null（入っていないのと同じ）
     *
     * @return array{version: string|int|null, size: int, path: string}|null
     */
    private function installed(DataSource $source, Store $store): ?array
    {
        if (! $store->hasData()) {
            return null;
        }
        $path = $store->dataPath();
        try {
            $version = $source->verify($path);
        } catch (Throwable) {
            return null;
        }

        return ['version' => $version, 'size' => (int) filesize($path), 'path' => $path];
    }

    /**
     * @param  array{version: string|int|null, size: int, path: string}|null  $installed
     *
     * @throws DataFileException
     */
    private function install(DataSource $source, Limits $limits, Store $store, string $file, ?array $installed, bool $force): RefreshResult
    {
        $name = $source->name();
        clearstatcache(true, $file);
        $size = (int) filesize($file);
        if ($size < $limits->minBytes) {
            throw new DataFileException("{$name}：取ったものが小さすぎます（{$size} バイト。下限 {$limits->minBytes} バイト）");
        }
        if ($size > $limits->maxBytes) {
            throw new DataFileException("{$name}：取ったものが大きすぎます（{$size} バイト。上限 {$limits->maxBytes} バイト）");
        }

        try {
            $version = $source->verify($file);
        } catch (Throwable $e) {
            throw new DataFileException("{$name}：取ったものを確かめで断りました（".$e::class.'）');
        }
        if (! is_int($version) && ! is_string($version) && $version !== null) {
            throw new DataFileException("{$name}：verify() は整数・文字列・null を返してください");
        }

        if ($installed !== null) {
            if (! $this->isNewer($name, $version, $installed['version'], $file, $installed['path'])) {
                $store->markChecked();
                $shown = $installed['version'] === null ? '' : "（{$installed['version']}）";

                return new RefreshResult(RefreshStatus::NotNewer, "{$name}：取ったものは入っている版{$shown}より新しくありません。入れ替えません", $installed['version']);
            }
            if ($limits->maxShrink !== null && ! $force && $size < $installed['size'] * (1 - $limits->maxShrink)) {
                throw new DataFileException(sprintf(
                    '%s：取ったものが入っているものより %d%% を超えて小さくなりました（%d → %d バイト）。確かめて正しければ --force で入れます',
                    $name, (int) round($limits->maxShrink * 100), $installed['size'], $size,
                ));
            }
        }

        $store->install($file, $name);
        $store->markChecked();
        $shown = $version === null ? '' : "（{$version}）";

        return new RefreshResult(RefreshStatus::Updated, "{$name}：新しい版{$shown}を入れました", $version);
    }

    /** @throws DataFileException */
    private function isNewer(string $name, string|int|null $candidate, string|int|null $installed, string $file, string $installedPath): bool
    {
        if ($candidate === null && $installed === null) {
            // 版を持たないデータ：中身が同じなら入れない（古い版で戻すことは防げない）
            return hash_file('sha256', $file) !== hash_file('sha256', $installedPath);
        }
        if (is_int($candidate) && is_int($installed)) {
            return $candidate > $installed;
        }
        if (is_string($candidate) && is_string($installed)) {
            return strcmp($candidate, $installed) > 0;
        }

        throw new DataFileException("{$name}：取ったものと入っているものの版の型が違います（整数・文字列・null をそろえてください）");
    }
}
