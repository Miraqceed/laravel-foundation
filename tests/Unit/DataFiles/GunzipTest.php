<?php

namespace Miraqceed\Foundation\Tests\Unit\DataFiles;

use Miraqceed\Foundation\DataFiles\DataFileException;
use Miraqceed\Foundation\DataFiles\Gunzip;
use PHPUnit\Framework\TestCase;

/** 設計書 10.1 の5つ目：inflate_add で、終わりまで解けたかを見分ける */
class GunzipTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/df-gunzip-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function gunzip(string $compressed, int $max = 10 * 1024 * 1024): string
    {
        file_put_contents($this->directory.'/in', $compressed);
        Gunzip::file('sample', $this->directory.'/in', $this->directory.'/out', $max);

        return (string) file_get_contents($this->directory.'/out');
    }

    private function failure(string $compressed, int $max = 10 * 1024 * 1024): string
    {
        try {
            $this->gunzip($compressed, $max);
        } catch (DataFileException $e) {
            return $e->getMessage();
        }
        $this->fail('解けてしまった');
    }

    public function test_decompresses_a_large_gzip_in_pieces(): void
    {
        $data = random_bytes(300_000).str_repeat('abc', 100_000);

        $this->assertSame($data, $this->gunzip(gzencode($data)));
    }

    public function test_decompresses_concatenated_members(): void
    {
        $this->assertSame('first second', $this->gunzip(gzencode('first ').gzencode('second')));
    }

    public function test_refuses_a_cut_gzip(): void
    {
        $compressed = gzencode(random_bytes(100_000));

        foreach ([8, 100, 50_000] as $cut) {
            $this->assertStringContainsString('gzip が途中で切れています', $this->failure(substr($compressed, 0, -$cut)), "後ろを {$cut} バイト切った");
        }
    }

    public function test_refuses_what_is_not_gzip_and_an_empty_body(): void
    {
        $this->assertStringContainsString('gzip として解けません', $this->failure("plain text\n"));
        $this->assertStringContainsString('gzip として解けません', $this->failure(gzencode('ok').'trailing'));
        $this->assertStringContainsString('本文が空です', $this->failure(''));
    }

    public function test_stops_when_the_output_grows_beyond_the_limit(): void
    {
        // 50MB の 0 を、メモリーに全体を持たずに gzip にする（約50KB）
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $compressed = '';
        $zeros = str_repeat("\0", 1024 * 1024);
        for ($i = 0; $i < 50; $i++) {
            $compressed .= deflate_add($context, $zeros, ZLIB_NO_FLUSH);
        }
        $compressed .= deflate_add($context, '', ZLIB_FINISH);

        $message = $this->failure($compressed, 1024 * 1024);

        $this->assertStringContainsString('解いた大きさが上限 1048576 バイトを超えました', $message);
        // 上限の少し上で止める（50MB を書かない）
        $this->assertLessThan(12 * 1024 * 1024, filesize($this->directory.'/out'));
    }
}
