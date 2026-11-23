<?php

namespace Miraqceed\Foundation\DataFiles;

use Miraqceed\Foundation\Failures\FailureReporter;
use RuntimeException;

/**
 * 1つの種類の置き場所：{置き場所}/{名前}/ の下に
 *   data       … 読む側が読むファイル（名前は固定）
 *   .lock      … flock のファイル（消さない）
 *   .checked   … 最後に更新を流して成功した時刻（mtime）
 *   .tmp-{乱数} … 一時のファイル（必ず同じディレクトリ。別のファイルシステムでは rename が写しと消しになる）
 *
 * @internal
 */
final class Store
{
    public const DATA = 'data';

    public const LOCK = '.lock';

    public const CHECKED = '.checked';

    public const TEMPORARY_PREFIX = '.tmp-';

    /** 強制終了で残った一時のファイルを消すまでの秒（書いている途中のものを消さない） */
    public const LEFTOVER_SECONDS = 3600;

    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly string $directory,
        private readonly FailureReporter $reporter = new FailureReporter,
    ) {}

    /** foundation.data_files.path（null なら storage_path('app/foundation/data-files')） */
    public static function basePath(): string
    {
        $path = config('foundation.data_files.path');

        return is_string($path) && $path !== '' ? rtrim($path, '/\\') : storage_path('app/foundation/data-files');
    }

    public static function for(string $name, ?FailureReporter $reporter = null): self
    {
        return new self(self::basePath().'/'.$name, $reporter ?? new FailureReporter);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function dataPath(): string
    {
        return $this->directory.'/'.self::DATA;
    }

    public function hasData(): bool
    {
        clearstatcache(true, $this->dataPath());

        return is_file($this->dataPath());
    }

    /** 最後に更新を流して成功した時刻（秒）。なければ null */
    public function checkedAt(): ?int
    {
        $path = $this->directory.'/'.self::CHECKED;
        clearstatcache(true, $path);
        $time = @filemtime($path);

        return $time === false ? null : $time;
    }

    /**
     * ロックを取る（待たない）。取れなければ false（ほかの実行が動いている）。
     * プロセスが終われば OS が放す
     */
    public function lock(): bool
    {
        $this->ensureDirectory();
        $handle = @fopen($this->directory.'/'.self::LOCK, 'c');
        if ($handle === false) {
            throw new RuntimeException('ロックのファイルを開けません');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->lock = $handle;

        return true;
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** 一時のファイルの道（置き場所と同じディレクトリ。まだ作らない） */
    public function temporaryPath(): string
    {
        $this->ensureDirectory();

        return $this->directory.'/'.self::TEMPORARY_PREFIX.bin2hex(random_bytes(8));
    }

    /** 強制終了で残った、1時間より古い一時のファイルを消す（ロックの中で呼ぶ） */
    public function removeLeftovers(): void
    {
        foreach (glob($this->directory.'/'.self::TEMPORARY_PREFIX.'*', GLOB_NOSORT) ?: [] as $file) {
            $time = @filemtime($file);
            if ($time !== false && $time < time() - self::LEFTOVER_SECONDS) {
                @unlink($file);
            }
        }
    }

    /**
     * 一時のファイルを置き場所に入れる：fsync → 権限をそろえる → rename（同じディレクトリなので、Linux のふつうのファイルシステムでは、
     * 読む側は前か後のどちらかだけを読む。Docker Desktop の bind mount・ネットワークのファイルシステムでは、入れ替えの最中に空のファイルを読むことがある。
     * 共用サーバーの storage では確かめていない。docs/data-files.md の限界）
     *
     * @throws DataFileException
     */
    public function install(string $temporary, string $name): void
    {
        $this->sync($temporary, $name);

        $mode = $this->hasData() ? (@fileperms($this->dataPath()) & 0777) : (0644 & ~umask());
        @chmod($temporary, $mode ?: 0644);

        if (! @rename($temporary, $this->dataPath())) {
            throw new DataFileException("{$name}：置き場所を入れ替えられません");
        }
    }

    /** 最後に更新を流して成功した時刻を今にする（置き場所のファイルには触らない） */
    public function markChecked(): void
    {
        $this->ensureDirectory();
        @touch($this->directory.'/'.self::CHECKED);
    }

    /** fsync が使えない・失敗したときは、記録して続ける（入れ替えは止めない。鍵ごとに1時間に1回） */
    private function sync(string $temporary, string $name): void
    {
        $handle = @fopen($temporary, 'r+b');
        $synced = $handle !== false && @fsync($handle);
        if ($handle !== false) {
            fclose($handle);
        }
        if (! $synced) {
            $this->reporter->report(new RuntimeException("データのファイル {$name}：一時のファイルを fsync できませんでした（入れ替えは続けます）"), "foundation-data-files-fsync:{$name}", 3600);
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('データのファイルの置き場所を作れません');
        }
    }
}
