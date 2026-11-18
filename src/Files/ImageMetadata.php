<?php

namespace Miraqceed\Foundation\Files;

use InvalidArgumentException;

/**
 * 写真のメタデータ（EXIF の GPS・撮った機械・XMP・IPTC・コメント）を、画像を作り直さずに器だけを書き直して消す。
 * 画素のデータはバイトのまま写すので、画質と色の情報（ICC）は変わらない。JPEG・PNG・WebP。ほかの種類はそのまま返す。
 *
 * - 向き（EXIF の Orientation）だけは残す：スマートフォンの写真は画素を回さず、向きで回して見せる（ブラウザは既定で従う）。
 *   消すと縦の写真が横を向くので、向きだけを持つ小さな EXIF に書き直す（向きが 1 ＝そのままなら入れない）
 * - JPEG は、残す塊を許す一覧で決める（APP0・ICC_PROFILE の APP2・APP14）。MPF の APP2 で EOI の後ろにつないだ2枚目の
 *   画像（そこにも GPS を持つことがある）と、後ろに足したデータを配らないよう、最初の EOI より後ろを捨てる
 * - PNG は IEND より後ろ、WebP は RIFF の大きさより後ろを捨てる
 * - 壊れた画像（塊の長さが合わない・終わりがない）は InvalidArgumentException。消せないまま返さない
 *
 * 全体を文字列で持つので、上げる大きさの上限と組で使う。
 */
final class ImageMetadata
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** PNG で除く塊 */
    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    private const EXIF_HEADER = "Exif\x00\x00";

    private const ORIENTATION_TAG = 0x0112;

    public static function strip(string $mimeType, string $bytes): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/jpeg', 'image/jpg' => self::jpeg($bytes),
            'image/png' => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default => $bytes,
        };
    }

    private static function jpeg(string $b): string
    {
        if (! str_starts_with($b, "\xFF\xD8")) {
            throw new InvalidArgumentException('JPEG でありません');
        }

        $len = strlen($b);
        $pos = 2;
        // JFIF の APP0 は SOI のすぐ後ろに置く決まりなので、向きの APP1 はその後ろに入れる
        $app0 = '';
        $rest = '';
        $orientation = null;

        while ($pos < $len) {
            if ($b[$pos] !== "\xFF") {
                throw new InvalidArgumentException('JPEG の印が壊れています');
            }
            // 印の前の詰めもの（FF の続き）
            while ($pos + 1 < $len && $b[$pos + 1] === "\xFF") {
                $pos++;
            }
            if ($pos + 1 >= $len) {
                break;
            }
            $marker = ord($b[$pos + 1]);

            if ($marker === 0xD9) { // EOI：ここで終わる。後ろ（つないだ2枚目の画像・足したデータ）は捨てる
                return "\xFF\xD8".$app0.self::jpegOrientation($orientation).$rest."\xFF\xD9";
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { // RSTn・TEM：長さがない
                $rest .= substr($b, $pos, 2);
                $pos += 2;

                continue;
            }
            if ($pos + 4 > $len) {
                throw new InvalidArgumentException('JPEG の塊が途中で切れています');
            }
            $segmentLength = self::number('n', substr($b, $pos + 2, 2));
            if ($segmentLength < 2 || $pos + 2 + $segmentLength > $len) {
                throw new InvalidArgumentException('JPEG の塊が途中で切れています');
            }
            $segment = substr($b, $pos, 2 + $segmentLength);
            $data = substr($segment, 4);
            $pos += 2 + $segmentLength;

            if ($marker === 0xDA) { // SOS：後ろの符号化されたデータを、次の印の前まで写す
                $end = self::jpegScanEnd($b, $pos);
                $rest .= $segment.substr($b, $pos, $end - $pos);
                $pos = $end;

                continue;
            }

            if ($marker >= 0xE0 && $marker <= 0xEF) {
                if ($marker === 0xE1 && $orientation === null && str_starts_with($data, self::EXIF_HEADER)) {
                    $orientation = self::orientation(substr($data, 6));
                }
                $keep = $marker === 0xE0
                    || ($marker === 0xE2 && str_starts_with($data, "ICC_PROFILE\x00"))
                    || $marker === 0xEE;
                if (! $keep) {
                    continue;
                }
                if ($marker === 0xE0 && $rest === '') {
                    $app0 .= $segment;

                    continue;
                }
            } elseif ($marker === 0xFE) { // COM
                continue;
            }

            $rest .= $segment;
        }

        throw new InvalidArgumentException('JPEG に終わり（EOI）がありません');
    }

    /** SOS の後の符号化されたデータの終わり（次の印の位置）。FF00（詰めもの）と RST は印でない */
    private static function jpegScanEnd(string $b, int $pos): int
    {
        $len = strlen($b);
        while (($pos = strpos($b, "\xFF", $pos)) !== false && $pos + 1 < $len) {
            $next = ord($b[$pos + 1]);
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7) || $next === 0xFF) {
                $pos += $next === 0xFF ? 1 : 2;

                continue;
            }

            return $pos;
        }

        throw new InvalidArgumentException('JPEG に終わり（EOI）がありません');
    }

    private static function jpegOrientation(?int $orientation): string
    {
        if ($orientation === null) {
            return '';
        }
        $payload = self::EXIF_HEADER.self::tiff($orientation);

        return "\xFF\xE1".pack('n', 2 + strlen($payload)).$payload;
    }

    private static function png(string $b): string
    {
        if (! str_starts_with($b, self::PNG_SIGNATURE)) {
            throw new InvalidArgumentException('PNG でありません');
        }

        $out = self::PNG_SIGNATURE;
        $pos = 8;
        $len = strlen($b);
        $orientation = null;
        $chunks = [];
        while ($pos < $len) {
            if ($pos + 12 > $len) {
                throw new InvalidArgumentException('PNG の塊が途中で切れています');
            }
            $dataLength = self::number('N', substr($b, $pos, 4));
            $type = substr($b, $pos + 4, 4);
            if ($pos + 12 + $dataLength > $len) {
                throw new InvalidArgumentException('PNG の塊が途中で切れています');
            }
            $chunk = substr($b, $pos, 12 + $dataLength);
            $pos += 12 + $dataLength;

            if ($type === 'eXIf' && $orientation === null) {
                $orientation = self::orientation(substr($chunk, 8, $dataLength));
            }
            if (! in_array($type, self::PNG_DROP, true)) {
                $chunks[] = [$type, $chunk];
            }
            if ($type === 'IEND') { // 後ろは捨てる
                foreach ($chunks as [$chunkType, $kept]) {
                    // 向きの eXIf は、最初の IDAT の前に入れる決まり
                    if ($chunkType === 'IDAT' && $orientation !== null) {
                        $out .= self::pngChunk('eXIf', self::tiff($orientation));
                        $orientation = null;
                    }
                    $out .= $kept;
                }

                return $out;
            }
        }

        throw new InvalidArgumentException('PNG に終わり（IEND）がありません');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function webp(string $b): string
    {
        if (strlen($b) < 12 || ! str_starts_with($b, 'RIFF') || substr($b, 8, 4) !== 'WEBP') {
            throw new InvalidArgumentException('WebP でありません');
        }

        // RIFF の大きさより後ろは捨てる
        $end = min(strlen($b), 8 + self::number('V', substr($b, 4, 4)));
        $pos = 12;
        $orientation = null;
        $chunks = '';
        $hasVp8x = false;
        while ($pos < $end) {
            if ($pos + 8 > $end) {
                throw new InvalidArgumentException('WebP の塊が途中で切れています');
            }
            $fourcc = substr($b, $pos, 4);
            $size = self::number('V', substr($b, $pos + 4, 4));
            if ($pos + 8 + $size > $end) {
                throw new InvalidArgumentException('WebP の塊が途中で切れています');
            }
            $chunk = substr($b, $pos, min(8 + $size + ($size % 2), $end - $pos));
            $pos += 8 + $size + ($size % 2);

            if ($fourcc === 'EXIF') {
                if ($orientation === null) {
                    $exif = substr($chunk, 8, $size);
                    $orientation = self::orientation(str_starts_with($exif, self::EXIF_HEADER) ? substr($exif, 6) : $exif);
                }

                continue;
            }
            if ($fourcc === 'XMP ') {
                continue;
            }
            if ($fourcc === 'VP8X' && $size >= 1) {
                $hasVp8x = true;
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // EXIF（0x08）と XMP（0x04）の印を外す
            }
            $chunks .= $chunk;
        }

        // 向きを入れられるのは VP8X のある形だけ（ない形に足すには、画像の大きさを持つ VP8X を作る要がある）
        if ($orientation !== null && $hasVp8x) {
            $flagAt = strpos($chunks, 'VP8X') + 8;
            $chunks[$flagAt] = chr(ord($chunks[$flagAt]) | 0x08);
            $tiff = self::tiff($orientation);
            $chunks .= 'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 === 1 ? "\x00" : '');
        }

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
    }

    /** TIFF（EXIF の中身）の IFD0 から向きを読む。2〜8 なら返し、1・読めないときは null（壊れた EXIF で止めない） */
    private static function orientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        $offset = (int) unpack($long, substr($tiff, 4, 4))[1];
        if ($offset < 8 || $offset + 2 > strlen($tiff)) {
            return null;
        }
        $count = (int) unpack($short, substr($tiff, $offset, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $offset + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if ((int) unpack($short, substr($tiff, $entry, 2))[1] !== self::ORIENTATION_TAG) {
                continue;
            }
            $value = (int) unpack($short, substr($tiff, $entry + 8, 2))[1];

            return $value >= 2 && $value <= 8 ? $value : null;
        }

        return null;
    }

    /** 向きだけを持つ小さな TIFF（リトルエンディアン、IFD0 に1項目） */
    private static function tiff(int $orientation): string
    {
        return 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', self::ORIENTATION_TAG).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
    }

    private static function number(string $format, string $bytes): int
    {
        $value = unpack($format, $bytes);
        if ($value === false || ! is_int($value[1] ?? null)) {
            throw new InvalidArgumentException('画像の頭が途中で切れています');
        }

        return $value[1];
    }
}
