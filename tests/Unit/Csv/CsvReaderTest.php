<?php

namespace Miraqceed\Foundation\Tests\Unit\Csv;

use Miraqceed\Foundation\Csv\CsvReader;
use Miraqceed\Foundation\Csv\CsvReadException;
use Miraqceed\Foundation\Csv\SourceEncoding;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{
    public function test_detects_encodings(): void
    {
        $this->assertSame(SourceEncoding::Utf8Bom, CsvReader::detect("\xEF\xBB\xBFabc"));
        $this->assertSame(SourceEncoding::Utf8, CsvReader::detect('a,b'));
        $this->assertSame(SourceEncoding::Utf8, CsvReader::detect('会社名'));

        // UTF-8 としても SJIS-win としても読める並び（「あい」の UTF-8）は UTF-8 を採る
        $both = "あい";
        $this->assertTrue(mb_check_encoding($both, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($both, 'SJIS-win'));
        $this->assertSame(SourceEncoding::Utf8, CsvReader::detect($both));

        $sjis = mb_convert_encoding('会社名', 'SJIS-win', 'UTF-8');
        $this->assertSame(SourceEncoding::ShiftJis, CsvReader::detect($sjis));

        $this->assertNull(CsvReader::detect("\xFF\xFF\xFF"));
    }

    public function test_parses_shift_jis_to_utf8(): void
    {
        $sjis = mb_convert_encoding("会社名,URL\n株式会社テスト,https://example.com\n", 'SJIS-win', 'UTF-8');

        $this->assertSame(
            [['会社名', 'URL'], ['株式会社テスト', 'https://example.com']],
            CsvReader::parse($sjis),
        );
    }

    public function test_parses_bom_and_crlf_and_skips_blank_lines(): void
    {
        $this->assertSame(
            [['a', 'b'], ['1', '2']],
            CsvReader::parse("\xEF\xBB\xBFa,b\r\n\r\n1,2\r\n"),
        );
    }

    public function test_drops_bom_even_when_utf8_is_given_explicitly(): void
    {
        // 利用者が「UTF-8」を選んでも、BOM 付きのファイルなら先頭のセルに BOM を残さない
        $this->assertSame(
            [['会社名', 'URL'], ['a', 'b']],
            CsvReader::parse("\xEF\xBB\xBF会社名,URL\na,b\n", SourceEncoding::Utf8),
        );
    }

    public function test_undetectable_bytes_are_read_as_utf8(): void
    {
        // detect() が null のとき、parse() は UTF-8 として続ける（説明書の約束）
        $this->assertNull(CsvReader::detect("a,\xFF"));
        $this->assertSame([['a', "\xFF"]], CsvReader::parse("a,\xFF\n"));
    }

    public function test_unguards_cells(): void
    {
        $this->assertSame([['=1+1']], CsvReader::parse("'=1+1\n"));
    }

    public function test_parse_does_not_treat_backslash_as_escape(): void
    {
        $this->assertSame([['a\\', 'b']], CsvReader::parse("\"a\\\",b\n"));
    }

    public function test_rejects_nul_bytes(): void
    {
        $this->expectException(CsvReadException::class);

        CsvReader::parse("a\0b");
    }
}
