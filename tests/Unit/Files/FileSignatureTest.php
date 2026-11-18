<?php

namespace Miraqceed\Foundation\Tests\Unit\Files;

use Miraqceed\Foundation\Files\FileSignature;
use PHPUnit\Framework\TestCase;

class FileSignatureTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function heads(): array
    {
        return [
            'PNG' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR", 'image/png'],
            'JPEG' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'image/jpeg'],
            'GIF87a' => ['GIF87a', 'image/gif'],
            'GIF89a' => ['GIF89a....', 'image/gif'],
            'WebP' => ["RIFF\x24\x00\x00\x00WEBPVP8 ", 'image/webp'],
            'PDF' => ["%PDF-1.7\n", 'application/pdf'],
            'HTML' => ['<!DOCTYPE html><html>', null],
            'SVG' => ['<?xml version="1.0"?><svg', null],
            'SVG（頭なし）' => ['<svg xmlns="http://www.w3.org/2000/svg">', null],
            'ZIP（Office）' => ["PK\x03\x04", null],
            'WAV（RIFF でも WEBP でない）' => ["RIFF\x24\x00\x00\x00WAVEfmt ", null],
            '空' => ['', null],
            '短い頭' => ["\x89PN", null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('heads')]
    public function test_先頭のバイトで見分ける(string $head, ?string $expected): void
    {
        $this->assertSame($expected, FileSignature::detect($head));
    }

    public function test_名前を変えたファイルは申告の種類と合わない(): void
    {
        $pdf = "%PDF-1.7\n";

        $this->assertFalse(FileSignature::matches('image/png', $pdf));
        $this->assertTrue(FileSignature::matches('application/pdf', $pdf));
        $this->assertTrue(FileSignature::matches(' Application/PDF ', $pdf));
        $this->assertFalse(FileSignature::matches('image/svg+xml', '<svg>'));
    }

    public function test_ストリームとファイルの先頭だけを読む(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\x89PNG\r\n\x1a\n".str_repeat("\x00", 100));
        rewind($stream);

        $this->assertSame('image/png', FileSignature::fromStream($stream));
        $this->assertSame(FileSignature::BYTES_NEEDED, ftell($stream));
        fclose($stream);

        $path = tempnam(sys_get_temp_dir(), 'foundation-signature-');
        file_put_contents($path, '%PDF-1.4');
        $this->assertSame('application/pdf', FileSignature::fromFile($path));
        unlink($path);

        $this->assertNull(FileSignature::fromFile('/nonexistent/file.png'));
    }
}
