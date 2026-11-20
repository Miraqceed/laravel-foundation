<?php

namespace Miraqceed\Foundation\Japan;

/**
 * 日本郵便の ZIP を読むためだけの小さな読み手（依存を足さない）。外から取ったものを信じない：
 *
 * - 中のファイルは**ちょうど1つ**で、名前が決まったもの（大文字・小文字は問わない）。名前は比べるだけで、道に使わない
 * - 圧縮の方式は「なし」と deflate だけ。暗号・ZIP64・分割は断る
 * - 展開後の大きさの上限。名乗る大きさが上限を超えれば解かない。gzinflate にも名乗る大きさを上限に渡し、それより広げない
 * - 解いた長さと CRC-32 が名乗りと違えば断る
 */
final class ZipEntries
{
    /** 展開後の大きさの上限（今の日本郵便の CSV は数十MB） */
    public const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private const END_OF_CENTRAL_DIRECTORY = 0x06054B50;

    private const ZIP64_END_LOCATOR = 0x07064B50;

    private const CENTRAL_FILE_HEADER = 0x02014B50;

    private const LOCAL_FILE_HEADER = 0x04034B50;

    private const STORED = 0;

    private const DEFLATED = 8;

    /** 終わりの印（22バイト）の後ろに置ける注記の長さの上限 */
    private const MAX_COMMENT = 0xFFFF;

    /**
     * ZIP の中のただ1つのファイル。名前が違う・ファイルが1つでない・読めない形なら PostalCodesException
     *
     * @return array{name: string, date: string, data: string} date は ZIP に書かれた更新日（YYYY-MM-DD）
     */
    public static function single(string $zip, string $expectedName, int $maxBytes = self::MAX_UNCOMPRESSED_BYTES): array
    {
        $end = self::findEnd($zip);

        $disk = self::u16($zip, $end + 4);
        $centralDisk = self::u16($zip, $end + 6);
        $onDisk = self::u16($zip, $end + 8);
        $total = self::u16($zip, $end + 10);
        $centralSize = self::u32($zip, $end + 12);
        $centralOffset = self::u32($zip, $end + 16);

        if ($end >= 20 && self::u32($zip, $end - 20) === self::ZIP64_END_LOCATOR) {
            throw new PostalCodesException('ZIP64 の形は読めません');
        }
        if ($disk !== 0 || $centralDisk !== 0 || $onDisk !== $total) {
            throw new PostalCodesException('分割した ZIP は読めません');
        }
        if ($total === 0xFFFF || $centralSize === 0xFFFFFFFF || $centralOffset === 0xFFFFFFFF) {
            throw new PostalCodesException('ZIP64 の形は読めません');
        }
        if ($total !== 1) {
            throw new PostalCodesException("ZIP の中のファイルが1つではありません（{$total}）");
        }
        if ($centralOffset + 46 > $end) {
            throw new PostalCodesException('ZIP の中央ディレクトリを読めません');
        }

        $at = $centralOffset;
        if (self::u32($zip, $at) !== self::CENTRAL_FILE_HEADER) {
            throw new PostalCodesException('ZIP の中央ディレクトリを読めません');
        }
        $flags = self::u16($zip, $at + 8);
        $method = self::u16($zip, $at + 10);
        $dosDate = self::u16($zip, $at + 14);
        $crc = self::u32($zip, $at + 16);
        $compressedSize = self::u32($zip, $at + 20);
        $size = self::u32($zip, $at + 24);
        $nameLength = self::u16($zip, $at + 28);
        $localOffset = self::u32($zip, $at + 42);
        if ($at + 46 + $nameLength > $end) {
            throw new PostalCodesException('ZIP の中央ディレクトリを読めません');
        }
        $name = substr($zip, $at + 46, $nameLength);

        // 名前は比べるだけ（道に使わない。'../' などを書く所がない）
        if (strtolower($name) !== strtolower($expectedName)) {
            throw new PostalCodesException("ZIP の中のファイルの名前が {$expectedName} ではありません");
        }
        if (($flags & 0x0001) !== 0 || ($flags & 0x0040) !== 0) {
            throw new PostalCodesException('暗号を掛けた ZIP は読めません');
        }
        if ($compressedSize === 0xFFFFFFFF || $size === 0xFFFFFFFF || $localOffset === 0xFFFFFFFF) {
            throw new PostalCodesException('ZIP64 の形は読めません');
        }
        if ($method !== self::STORED && $method !== self::DEFLATED) {
            throw new PostalCodesException("ZIP の圧縮の方式 {$method} は読めません");
        }
        if ($size > $maxBytes) {
            throw new PostalCodesException("ZIP の中のファイルが大きすぎます（展開後 {$size} バイト。上限 {$maxBytes} バイト）");
        }

        if ($localOffset + 30 > strlen($zip) || self::u32($zip, $localOffset) !== self::LOCAL_FILE_HEADER) {
            throw new PostalCodesException('ZIP の中のファイルの見出しを読めません');
        }
        $start = $localOffset + 30 + self::u16($zip, $localOffset + 26) + self::u16($zip, $localOffset + 28);
        if ($start + $compressedSize > $centralOffset) {
            throw new PostalCodesException('ZIP の中のファイルの長さが合いません');
        }
        $raw = substr($zip, $start, $compressedSize);

        if ($method === self::STORED) {
            $data = $raw;
        } else {
            // 名乗る大きさより広げない（0 は上限なしの意味になるので、1 にして長さの比べで断る）
            $data = @gzinflate($raw, max(1, $size));
            if ($data === false) {
                throw new PostalCodesException('ZIP の中のファイルを解けません（壊れているか、名乗る大きさより大きく広がる）');
            }
        }
        if (strlen($data) !== $size) {
            throw new PostalCodesException('ZIP の中のファイルの長さが、名乗る大きさと違います');
        }
        if (crc32($data) !== $crc) {
            throw new PostalCodesException('ZIP の中のファイルの CRC-32 が合いません');
        }

        return ['name' => $name, 'date' => self::date($dosDate), 'data' => $data];
    }

    /** 終わりの印の位置。注記の長さが合う印を、後ろから探す */
    private static function findEnd(string $zip): int
    {
        $length = strlen($zip);
        $lowest = max(0, $length - 22 - self::MAX_COMMENT);
        for ($at = $length - 22; $at >= $lowest; $at--) {
            if (self::u32($zip, $at) === self::END_OF_CENTRAL_DIRECTORY && $at + 22 + self::u16($zip, $at + 20) === $length) {
                return $at;
            }
        }

        throw new PostalCodesException('ZIP の終わりの印が見つかりません（ZIP でないか、壊れている）');
    }

    private static function date(int $dosDate): string
    {
        return sprintf('%04d-%02d-%02d', ($dosDate >> 9) + 1980, ($dosDate >> 5) & 15, $dosDate & 31);
    }

    private static function u16(string $bytes, int $at): int
    {
        return unpack('v', $bytes, $at)[1];
    }

    private static function u32(string $bytes, int $at): int
    {
        return unpack('V', $bytes, $at)[1];
    }
}
