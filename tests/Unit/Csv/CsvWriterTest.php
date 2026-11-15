<?php

namespace Miraqceed\Foundation\Tests\Unit\Csv;

use Miraqceed\Foundation\Csv\CsvWriteException;
use Miraqceed\Foundation\Csv\CsvWriter;
use Miraqceed\Foundation\Csv\Encoding;
use PHPUnit\Framework\TestCase;

class CsvWriterTest extends TestCase
{
    private function write(Encoding $e, array ...$rows): string
    {
        $s = fopen('php://memory', 'w+');
        $w = new CsvWriter($s, $e);
        foreach ($rows as $r) {
            $w->writeRow($r);
        }
        rewind($s);

        return stream_get_contents($s);
    }

    public function test_backslash_quote_does_not_break_out_of_cell(): void
    {
        $out = $this->write(Encoding::ShiftJis, ['a\\"', '=1']);
        $this->assertSame(['a\\"', "'=1"], str_getcsv(rtrim($out), ',', '"', ''));
    }

    public function test_utf8_writes_bom_once_and_crlf(): void
    {
        $out = $this->write(Encoding::Utf8Bom, ['a', 'b'], ['c', 'd']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $out);
        $this->assertSame(1, substr_count($out, "\xEF\xBB\xBF"));
        $this->assertStringEndsWith("\r\n", $out);
        $this->assertSame("\xEF\xBB\xBFa,b\r\nc,d\r\n", $out);
    }

    public function test_shift_jis_converts_with_sjis_win_and_no_bom(): void
    {
        $out = $this->write(Encoding::ShiftJis, ['①髙']);
        $this->assertSame(mb_convert_encoding('①髙', 'SJIS-win', 'UTF-8')."\r\n", $out);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $out);
    }

    public function test_shift_jis_replaces_unmappable_characters(): void
    {
        $out = $this->write(Encoding::ShiftJis, ['a🍣b']);
        $this->assertSame("a?b\r\n", $out);
    }

    public function test_substitute_character_is_restored(): void
    {
        mb_substitute_character(0x2A);
        try {
            $this->write(Encoding::ShiftJis, ['x']);
            $this->assertSame(0x2A, mb_substitute_character());
        } finally {
            mb_substitute_character(0x3F);
        }
    }

    public function test_write_failure_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $s = fopen($path, 'r');
        try {
            $this->expectException(CsvWriteException::class);
            (new CsvWriter($s, Encoding::ShiftJis))->writeRow(['a']);
        } finally {
            fclose($s);
            unlink($path);
        }
    }

    public function test_bom_write_failure_throws_and_bom_is_retried(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $ro = fopen($path, 'r');
        $writer = new CsvWriter($ro, Encoding::Utf8Bom);
        try {
            $writer->writeRow(['a']);
            $this->fail('例外になるはず');
        } catch (CsvWriteException) {
        }
        fclose($ro);

        // 失敗を握りつぶして続けても、BOM なしの UTF-8 にならない（また BOM から書く）
        $rw = fopen('php://memory', 'w+');
        $ref = new \ReflectionProperty($writer, 'stream');
        $ref->setValue($writer, $rw);
        $writer->writeRow(['a']);
        rewind($rw);
        $this->assertSame("\xEF\xBB\xBFa\r\n", stream_get_contents($rw));
        unlink($path);
    }

    public function test_short_write_throws(): void
    {
        LimitedStream::$limit = 5;
        LimitedStream::$data = '';
        stream_wrapper_register('limited', LimitedStream::class);
        try {
            $s = fopen('limited://x', 'w');
            $this->expectException(CsvWriteException::class);
            (new CsvWriter($s, Encoding::ShiftJis))->writeRow(['abcdefghij']);
        } finally {
            stream_wrapper_unregister('limited');
        }
    }
}

/** 書ける量を制限したストリーム */
class LimitedStream
{
    public static int $limit = 0;

    public static string $data = '';

    public $context;

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $room = max(0, self::$limit - strlen(self::$data));
        $take = substr($data, 0, $room);
        self::$data .= $take;

        return strlen($take);
    }
}
