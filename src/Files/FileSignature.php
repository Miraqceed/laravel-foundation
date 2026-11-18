<?php

namespace Miraqceed\Foundation\Files;

/**
 * 先頭のバイトで、ファイルの種類を見分ける。拡張子と申告の種類（Content-Type）は利用者が決められるので、
 * 名前を変えたファイル（.png にした HTML・PDF）を断るのに使う。
 *
 * 見分けるのは、受けてよい種類の許す一覧（TYPES）だけ。SVG・HTML・Office・実行ファイルは、どれも null（分からない）。
 * Laravel の UploadedFile を通る上げ方では、検証の規則 mimes・extensions・image を使う（docs/files.md）。
 * これは、署名つきの URL で直に上げたファイルなど、ストレージから先頭だけを読んで確かめるとき向け。
 */
final class FileSignature
{
    /** 見分けるのに読む先頭のバイト数 */
    public const BYTES_NEEDED = 16;

    public const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    /** 先頭のバイトから種類（TYPES のどれか）。分からなければ null */
    public static function detect(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    /** 申告の種類（大文字・小文字は問わない）と、先頭のバイトが合うか */
    public static function matches(string $mimeType, string $head): bool
    {
        $detected = self::detect($head);

        return $detected !== null && $detected === strtolower(trim($mimeType));
    }

    /** ファイルの先頭だけを読んで見分ける。読めなければ null */
    public static function fromFile(string $path): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            return self::fromStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * ストリームの先頭だけを読んで見分ける（ストレージの readStream() など）。ストリームは閉じない
     *
     * @param  resource  $stream
     */
    public static function fromStream($stream): ?string
    {
        $head = '';
        while (strlen($head) < self::BYTES_NEEDED && ! feof($stream)) {
            $chunk = fread($stream, self::BYTES_NEEDED - strlen($head));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
        }

        return self::detect($head);
    }
}
