<?php

namespace Miraqceed\Foundation\Csv;

/**
 * CSV を行の配列へ読む。UTF-8・BOM 付き UTF-8・Shift_JIS（CP932）に対応する。
 *
 * 日本の業務では表計算ソフトが出す Shift_JIS の CSV が一般的で、対応しないと文字化けの失敗が常態化する。
 */
class CsvReader
{
    /** 文字コードを判定する。判定できなければ null（呼び出し側が利用者に指定させる） */
    public static function detect(string $bytes): ?SourceEncoding
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return SourceEncoding::Utf8Bom;
        }

        // ASCII だけならどちらで読んでも同じ
        if (mb_check_encoding($bytes, 'ASCII')) {
            return SourceEncoding::Utf8;
        }

        // 両方として読めるときは UTF-8 を採る。Shift_JIS のファイルが UTF-8 として妥当になるのは稀で、
        // 逆（UTF-8 が Shift_JIS として妥当）は起こりうる
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return SourceEncoding::Utf8;
        }

        if (mb_check_encoding($bytes, 'SJIS-win')) {
            return SourceEncoding::ShiftJis;
        }

        return null;
    }

    /**
     * 行の配列へ変換する（見出しも1行目としてそのまま返す）。BOM は落とし、空行は飛ばす。
     * 書き出しが式の印として足した「'」は外す（CellGuard）。
     *
     * @return list<list<string>>
     *
     * @throws CsvReadException NUL バイトを含むとき
     */
    public static function parse(string $bytes, ?SourceEncoding $encoding = null): array
    {
        if (str_contains($bytes, "\0")) {
            throw new CsvReadException('CSV に NUL バイトが含まれています。');
        }

        $encoding ??= self::detect($bytes) ?? SourceEncoding::Utf8;

        if ($encoding === SourceEncoding::Utf8Bom) {
            $bytes = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        } elseif ($encoding === SourceEncoding::ShiftJis) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        }

        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);

        // エスケープ文字は使わない（\" で引用符が閉じて次のセルへ続かないように）
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null] || $row === ['']) {
                continue;
            }
            $rows[] = array_map(fn ($v) => trim(CellGuard::unguard((string) $v)), $row);
        }

        fclose($handle);

        return $rows;
    }
}
