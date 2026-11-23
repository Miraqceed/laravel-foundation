<?php

namespace Miraqceed\Foundation\Integrity;

use Throwable;

/**
 * ファイルの SHA-256 を、1MB ずつ読みながら期限を見て取る。開いた後に fstat で、ふつうのファイルか・lstat と同じものかを確かめる
 * （lstat の後に名前つきのパイプへ差し替えられても読まない。fopen そのものが返らない分は残る）
 *
 * @internal
 */
final class Fingerprint
{
    public const CHUNK = 1024 * 1024;

    /** 期限で打ち切った */
    public const TIMED_OUT = false;

    /**
     * @return string|false|null 16進の SHA-256。読めなければ null、期限で打ち切れば false
     */
    public static function file(Entry $entry, Deadline $deadline): string|false|null
    {
        if ($entry->type !== Entry::FILE || $entry->stat === null) {
            return null;
        }
        try {
            $handle = @fopen($entry->absolute, 'rb');
        } catch (Throwable) {
            return null;
        }
        if ($handle === false) {
            return null;
        }
        try {
            $stat = @fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
                || $stat['ino'] !== $entry->stat['ino'] || $stat['dev'] !== $entry->stat['dev']) {
                return null;
            }
            $context = hash_init('sha256');
            while (! feof($handle)) {
                if ($deadline->passed()) {
                    return self::TIMED_OUT;
                }
                $read = @hash_update_stream($context, $handle, self::CHUNK);
                if ($read === 0 && ! feof($handle)) {
                    return null;
                }
            }

            return hash_final($context);
        } catch (Throwable) {
            return null;
        } finally {
            @fclose($handle);
        }
    }

    /** @return string|null 先の16進（読めなければ null） */
    public static function link(Entry $entry): ?string
    {
        try {
            $target = @readlink($entry->absolute);
        } catch (Throwable) {
            return null;
        }

        return $target === false ? null : bin2hex($target);
    }
}
