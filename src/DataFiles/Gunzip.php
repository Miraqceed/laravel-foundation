<?php

namespace Miraqceed\Foundation\DataFiles;

/**
 * gzip を流しながら解く（メモリーに全体を読まない）。
 * - 解いた大きさが上限を超えたら、その場で止める（配る側の誤りで、ディスクを埋めない）
 * - 終わりまで解けたか（gzip の終わりの印と CRC）を見る。切れた gzip は失敗（compress.zlib:// は途中までを黙って返しうる）
 * - gzip でない本文は失敗。いくつかの gzip をつないだもの（RFC 1952 の複数の member）は続けて解く
 *
 * @internal
 */
final class Gunzip
{
    /** 一度に渡す大きさ。deflate は1バイトから約1000バイトに広がりうるので、小さく渡して解いた大きさの上限を超える前に止める（約8MB まで） */
    private const CHUNK = 8192;

    /** @throws DataFileException */
    public static function file(string $name, string $from, string $to, int $maxBytes): void
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            throw new DataFileException("{$name}：一時のファイルを開けません");
        }

        try {
            $context = self::context($name);
            $written = 0;
            $ended = false;
            $sawInput = false;
            $pending = '';
            // 今の member の context に渡した、この塊より前のバイトの数（inflate_get_read_len は context ごとの通しの数）
            $fedBefore = 0;

            while (true) {
                if ($pending === '') {
                    if (feof($in)) {
                        break;
                    }
                    $pending = (string) fread($in, self::CHUNK);
                    if ($pending === '') {
                        continue;
                    }
                }
                $sawInput = true;
                if ($ended) {
                    // 前の member の後ろに続きがある：次の member として解く
                    $context = self::context($name);
                    $ended = false;
                    $fedBefore = 0;
                }

                $chunk = $pending;
                $pending = '';
                $output = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                if ($output === false) {
                    throw new DataFileException("{$name}：gzip として解けません");
                }
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    $ended = true;
                    // この塊のうち、member の終わりより後ろは次の member
                    $pending = (string) substr($chunk, inflate_get_read_len($context) - $fedBefore);
                } else {
                    $fedBefore += strlen($chunk);
                }

                $written += strlen($output);
                if ($written > $maxBytes) {
                    throw new DataFileException("{$name}：解いた大きさが上限 {$maxBytes} バイトを超えました");
                }
                if ($output !== '' && fwrite($out, $output) !== strlen($output)) {
                    throw new DataFileException("{$name}：一時のファイルに書けません");
                }
            }

            if (! $sawInput) {
                throw new DataFileException("{$name}：本文が空です");
            }
            if (! $ended) {
                throw new DataFileException("{$name}：gzip が途中で切れています");
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @return \InflateContext */
    private static function context(string $name)
    {
        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new DataFileException("{$name}：gzip を解く用意ができません");
        }

        return $context;
    }
}
