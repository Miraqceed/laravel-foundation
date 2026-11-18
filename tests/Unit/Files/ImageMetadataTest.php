<?php

namespace Miraqceed\Foundation\Tests\Unit\Files;

use InvalidArgumentException;
use Miraqceed\Foundation\Files\ImageMetadata;
use PHPUnit\Framework\TestCase;

/**
 * 試しの画像は、ここで組み立てる作り物（本物の写真・位置の情報を使わない）。器の形だけを持ち、描ける画像ではない
 */
class ImageMetadataTest extends TestCase
{
    /** 作り物の位置の情報（消えたことを、この字が残っていないことで見る） */
    private const SECRET = 'GPS 35.000000,139.000000';

    // ---- 組み立ての助け ----

    /** 向きと説明（位置の情報の代わり）を IFD0 に持つ TIFF */
    private static function tiff(int $orientation, string $order = 'II'): string
    {
        $s = $order === 'II' ? 'v' : 'n';
        $l = $order === 'II' ? 'V' : 'N';
        $text = self::SECRET."\x00";
        $textOffset = 8 + 2 + 2 * 12 + 4;

        return $order.pack($s, 42).pack($l, 8)
            .pack($s, 2)
            .pack($s, 0x010E).pack($s, 2).pack($l, strlen($text)).pack($l, $textOffset)
            .pack($s, 0x0112).pack($s, 3).pack($l, 1).pack($s, $orientation).pack($s, 0)
            .pack($l, 0)
            .$text;
    }

    private static function segment(int $marker, string $data): string
    {
        return "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;
    }

    private static function jpeg(int $orientation = 6, bool $progressive = false): string
    {
        $scan = "\x12\x34\xFF\x00\x56\xFF\xD3\x78\x9A";
        $scans = self::segment(0xDA, "\x01\x01\x00\x00\x3F\x00").$scan;
        if ($progressive) {
            $scans .= self::segment(0xC4, 'second-huffman').self::segment(0xDA, "\x01\x01\x00\x01\x3F\x00")."\xAB\xFF\x00\xCD";
        }
        $second = "\xFF\xD8".self::segment(0xE1, "Exif\x00\x00".self::tiff(1)).self::segment(0xDA, "\x01").'x'."\xFF\xD9";

        return "\xFF\xD8"
            .self::segment(0xE0, "JFIF\x00\x01\x01")
            .self::segment(0xE1, "Exif\x00\x00".self::tiff($orientation))
            .self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta>".self::SECRET.'</x:xmpmeta>')
            .self::segment(0xE2, "MPF\x00multi-picture")
            .self::segment(0xE2, "ICC_PROFILE\x00\x01\x01color-profile")
            .self::segment(0xED, "Photoshop 3.0\x00".self::SECRET)
            .self::segment(0xEE, "Adobe\x00\x64")
            .self::segment(0xFE, 'comment '.self::SECRET)
            .self::segment(0xDB, 'quantization')
            .self::segment(0xC0, 'frame')
            .self::segment(0xC4, 'huffman')
            .$scans
            ."\xFF\xD9"
            .$second
            .'TRAILING '.self::SECRET;
    }

    /** @return list<array{int, string}> JPEG の印と中身の並び（SOI と EOI を除く） */
    private static function jpegSegments(string $jpeg): array
    {
        $segments = [];
        $pos = 2;
        while ($pos < strlen($jpeg)) {
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xD9) {
                break;
            }
            $length = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            $segments[] = [$marker, substr($jpeg, $pos + 4, $length - 2)];
            $pos += 2 + $length;
            if ($marker === 0xDA) {
                // 試しの符号化されたデータは、次の FF xx（00・RST でない）まで
                while (! ($jpeg[$pos] === "\xFF" && ! in_array(ord($jpeg[$pos + 1]), [0x00, 0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7], true))) {
                    $pos++;
                }
            }
        }

        return $segments;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function png(int $orientation = 6): string
    {
        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', str_repeat("\x00", 13))
            .self::chunk('tEXt', "Comment\x00".self::SECRET)
            .self::chunk('eXIf', self::tiff($orientation))
            .self::chunk('iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00".self::SECRET)
            .self::chunk('iCCP', "icc\x00\x00profile")
            .self::chunk('IDAT', 'pixel-data')
            .self::chunk('tIME', "\x07\xEA\x0B\x12\x0A\x00\x00")
            .self::chunk('IEND', '')
            .'TRAILING '.self::SECRET;
    }

    /** @return list<array{string, string}> PNG の塊の種類と中身（CRC も確かめる） */
    private function pngChunks(string $png): array
    {
        $chunks = [];
        $pos = 8;
        while ($pos < strlen($png)) {
            $length = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $length);
            $this->assertSame(crc32($type.$data), unpack('N', substr($png, $pos + 8 + $length, 4))[1], "{$type} の CRC");
            $chunks[] = [$type, $data];
            $pos += 12 + $length;
        }

        return $chunks;
    }

    private static function webpChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
    }

    private static function webp(bool $vp8x = true, int $orientation = 6): string
    {
        $chunks = ($vp8x ? self::webpChunk('VP8X', chr(0x20 | 0x08 | 0x04)."\x00\x00\x00".str_repeat("\x00", 6)) : '')
            .($vp8x ? self::webpChunk('ICCP', 'color-profile') : '')
            .self::webpChunk('VP8 ', 'pixel-data')
            .self::webpChunk('EXIF', "Exif\x00\x00".self::tiff($orientation))
            .self::webpChunk('XMP ', '<x:xmpmeta>'.self::SECRET.'</x:xmpmeta>');

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks.'TRAILING '.self::SECRET;
    }

    /** @return array<string, string> WebP の塊の種類と中身 */
    private function webpChunks(string $webp): array
    {
        $this->assertSame(strlen($webp) - 8, unpack('V', substr($webp, 4, 4))[1], 'RIFF の大きさ');
        $chunks = [];
        $pos = 12;
        while ($pos < strlen($webp)) {
            $size = unpack('V', substr($webp, $pos + 4, 4))[1];
            $chunks[substr($webp, $pos, 4)] = substr($webp, $pos + 8, $size);
            $pos += 8 + $size + ($size % 2);
        }

        return $chunks;
    }

    /** 向きだけの TIFF（ほかの項目がない）から向きを読む */
    private function onlyOrientation(string $tiff): int
    {
        $this->assertSame('II', substr($tiff, 0, 2));
        $this->assertSame(1, unpack('v', substr($tiff, 8, 2))[1], 'IFD0 の項目は向きの1つだけ');
        $this->assertSame(0x0112, unpack('v', substr($tiff, 10, 2))[1]);

        return unpack('v', substr($tiff, 18, 2))[1];
    }

    // ---- JPEG ----

    public function test_JPEG_の位置の情報と_EOI_の後ろが消え_向きと色の情報が残る(): void
    {
        $out = ImageMetadata::strip('image/jpeg', self::jpeg());

        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString('xmpmeta', $out);
        $this->assertStringNotContainsString('MPF', $out);
        $this->assertStringNotContainsString('TRAILING', $out);
        $this->assertStringEndsWith("\xFF\xD9", $out);
        $this->assertSame(1, substr_count($out, "\xFF\xD8"), 'つないだ2枚目の SOI がない');

        $segments = self::jpegSegments($out);
        $markers = array_column($segments, 0);
        // APP0 が最初、その後に向きの APP1、ICC の APP2、APP14、画像の塊
        $this->assertSame([0xE0, 0xE1, 0xE2, 0xEE, 0xDB, 0xC0, 0xC4, 0xDA], $markers);
        $this->assertStringStartsWith('ICC_PROFILE', $segments[2][1]);
        $this->assertStringStartsWith("Exif\x00\x00", $segments[1][1]);
        $this->assertSame(6, $this->onlyOrientation(substr($segments[1][1], 6)));
        // 符号化されたデータ（FF00・RST を含む）はバイトのまま
        $this->assertStringContainsString("\x12\x34\xFF\x00\x56\xFF\xD3\x78\x9A\xFF\xD9", $out);
    }

    public function test_JPEG_の向きはビッグエンディアンでも読む(): void
    {
        $jpeg = str_replace("Exif\x00\x00".self::tiff(6), "Exif\x00\x00".self::tiff(8, 'MM'), self::jpeg());

        $segments = self::jpegSegments(ImageMetadata::strip('image/jpeg', $jpeg));

        $this->assertSame(8, $this->onlyOrientation(substr($segments[1][1], 6)));
    }

    public function test_JPEG_の向きが_1_なら_EXIF_を入れない(): void
    {
        $markers = array_column(self::jpegSegments(ImageMetadata::strip('image/jpeg', self::jpeg(orientation: 1))), 0);

        $this->assertNotContains(0xE1, $markers);
    }

    public function test_プログレッシブの_JPEG_は最後の走査まで残る(): void
    {
        $out = ImageMetadata::strip('image/jpeg', self::jpeg(progressive: true));

        $this->assertStringContainsString('second-huffman', $out);
        $this->assertStringEndsWith("\xAB\xFF\x00\xCD\xFF\xD9", $out);
        $this->assertStringNotContainsString('TRAILING', $out);
    }

    public function test_壊れた_JPEG_は例外(): void
    {
        foreach ([
            '頭が違う' => 'not a jpeg',
            '塊が途中で切れる' => substr(self::jpeg(), 0, 30),
            'EOI がない' => "\xFF\xD8".self::segment(0xDB, 'q').self::segment(0xDA, "\x01").'data',
        ] as $label => $broken) {
            try {
                ImageMetadata::strip('image/jpeg', $broken);
                $this->fail("{$label} で例外にならない");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- PNG ----

    public function test_PNG_の位置の情報と_IEND_の後ろが消え_向きが_IDAT_の前に残る(): void
    {
        $out = ImageMetadata::strip('image/png', self::png());

        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString('TRAILING', $out);
        $chunks = $this->pngChunks($out);
        $this->assertSame(['IHDR', 'iCCP', 'eXIf', 'IDAT', 'IEND'], array_column($chunks, 0));
        $this->assertSame(6, $this->onlyOrientation($chunks[2][1]));
        $this->assertSame('pixel-data', $chunks[3][1]);
    }

    public function test_PNG_の向きが_1_なら_eXIf_を入れない(): void
    {
        $this->assertSame(['IHDR', 'iCCP', 'IDAT', 'IEND'], array_column($this->pngChunks(ImageMetadata::strip('image/png', self::png(1))), 0));
    }

    public function test_壊れた_PNG_は例外(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageMetadata::strip('image/png', substr(self::png(), 0, 40));
    }

    // ---- WebP ----

    public function test_WebP_の位置の情報と後ろが消え_向きと色の情報が残る(): void
    {
        $out = ImageMetadata::strip('image/webp', self::webp());

        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString('TRAILING', $out);
        $chunks = $this->webpChunks($out);
        $this->assertSame(['VP8X', 'ICCP', 'VP8 ', 'EXIF'], array_keys($chunks));
        // ICC（0x20）は残し、XMP（0x04）を外し、向きの EXIF（0x08）を立てる
        $this->assertSame(0x20 | 0x08, ord($chunks['VP8X'][0]));
        $this->assertSame(6, $this->onlyOrientation($chunks['EXIF']));
        $this->assertSame('pixel-data', $chunks['VP8 ']);
    }

    public function test_VP8X_のない_WebP_は向きも消える(): void
    {
        $chunks = $this->webpChunks(ImageMetadata::strip('image/webp', self::webp(vp8x: false)));

        $this->assertSame(['VP8 '], array_keys($chunks));
    }

    public function test_向きが_1_の_WebP_は_EXIF_の印を外す(): void
    {
        $chunks = $this->webpChunks(ImageMetadata::strip('image/webp', self::webp(orientation: 1)));

        $this->assertSame(['VP8X', 'ICCP', 'VP8 '], array_keys($chunks));
        $this->assertSame(0x20, ord($chunks['VP8X'][0]));
    }

    // ---- ほか ----

    public function test_ほかの種類はそのまま返す(): void
    {
        $this->assertSame('%PDF-1.7 '.self::SECRET, ImageMetadata::strip('application/pdf', '%PDF-1.7 '.self::SECRET));
        $this->assertSame('GIF89a', ImageMetadata::strip('image/gif', 'GIF89a'));
    }
}
