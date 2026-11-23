<?php

namespace Miraqceed\Foundation\Integrity;

use Throwable;

/**
 * 子を使えないとき（親の中で画面を流すとき）の、この回の印のファイル views-running.{pid}.{乱数}。中身は始めた時刻・pid・乱数。
 * 画面の調べの途中で親が落ちると残り、次の回と点検が拾う。消すのは自分の印だけ（手で流した命令が重なっても、ほかの回の印を消さない）。
 * 置き場所のディレクトリ（一覧の置き場所）がなければ作らない（入れただけでは何も作らない）
 *
 * @internal
 */
final class ViewsMark
{
    public const PREFIX = 'views-running.';

    private function __construct(private readonly string $path, private readonly string $contents) {}

    /** 書けなければ null */
    public static function write(): ?self
    {
        $directory = dirname(Snapshot::path());
        if (! is_dir($directory)) {
            return null;
        }
        $nonce = bin2hex(random_bytes(6));
        $pid = (int) getmypid();
        $path = $directory.'/'.self::PREFIX.$pid.'.'.$nonce;
        $contents = time().' '.$pid.' '.$nonce;
        try {
            if (@file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
                @unlink($path);

                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return new self($path, $contents);
    }

    /** 自分の印だけを消す（名前と中身が自分の回と合うときだけ） */
    public function remove(): void
    {
        try {
            if (is_file($this->path) && @file_get_contents($this->path, false, null, 0, 128) === $this->contents) {
                @unlink($this->path);
            }
        } catch (Throwable) {
            // 消せなければ、次の回が「前の回が落ちた」と言う（誤報の側に倒す）
        }
    }

    /** 動いていない回の印を数えて消す（動いている回のものには触らない） */
    public static function claimCrashed(): int
    {
        return self::scan(remove: true);
    }

    /** 動いていない回の印が残っているか（点検。消さない） */
    public static function crashedLeft(): bool
    {
        return self::scan(remove: false) > 0;
    }

    private static function scan(bool $remove): int
    {
        $directory = dirname(Snapshot::path());
        $handle = is_dir($directory) ? @opendir($directory) : false;
        if ($handle === false) {
            return 0;
        }
        $crashed = 0;
        $seen = 0;
        while (($name = @readdir($handle)) !== false && $seen < 1000) {
            if (! str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $seen++;
            $path = $directory.'/'.$name;
            $contents = (string) @file_get_contents($path, false, null, 0, 128);
            if (preg_match('/\A(\d{1,12}) (\d{1,10}) ([0-9a-f]{12})\z/', $contents, $match) === 1
                && $name === self::PREFIX.$match[2].'.'.$match[3] && self::alive((int) $match[2], (int) $match[1])) {
                continue;
            }
            $crashed++;
            if ($remove) {
                @unlink($path);
            }
        }
        closedir($handle);

        return $crashed;
    }

    /** その回が今も動いているか（pid が生きていて、始めた時刻が期限の内） */
    private static function alive(int $pid, int $started): bool
    {
        $window = (int) config('foundation.integrity.seconds', 240) + 60;
        if ($started < time() - $window) {
            return false;
        }
        if (! function_exists('posix_kill')) {
            return false;
        }

        return $pid === getmypid() || @posix_kill($pid, 0);
    }
}
