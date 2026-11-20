<?php

namespace Miraqceed\Foundation\Tests\Unit\Japan;

use Miraqceed\Foundation\Japan\PostalCodesException;
use Miraqceed\Foundation\Japan\ZipEntries;
use Miraqceed\Foundation\Tests\Support\Japan\ZipBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 日本郵便の ZIP を読む小さな読み手。外から取ったものを信じない（数・名前・方式・暗号・展開後の大きさ・CRC）
 */
class ZipEntriesTest extends TestCase
{
    public function test_中のファイルと日付を読む(): void
    {
        $entry = ZipEntries::single(ZipBuilder::single('UTF_KEN_ALL.CSV', "行1\r\n行2\r\n", '2026-10-23'), 'utf_ken_all.csv');

        $this->assertSame('UTF_KEN_ALL.CSV', $entry['name']);
        $this->assertSame("行1\r\n行2\r\n", $entry['data']);
        $this->assertSame('2026-10-23', $entry['date']);
    }

    public function test_圧縮しない形も読む(): void
    {
        $zip = ZipBuilder::make()->add('JIGYOSYO.CSV', 'abc', method: 0)->build();

        $this->assertSame('abc', ZipEntries::single($zip, 'JIGYOSYO.CSV')['data']);
    }

    public function test_空のファイルも読む(): void
    {
        $this->assertSame('', ZipEntries::single(ZipBuilder::single('a.csv', ''), 'a.csv')['data']);
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            '中のファイルが2つ' => [ZipBuilder::make()->add('utf_ken_all.csv', 'a')->add('other.csv', 'b')->build(), '1つではありません'],
            '中のファイルがない' => [ZipBuilder::make()->build(), '1つではありません'],
            '名前の違う CSV' => [ZipBuilder::single('ken_all.csv', 'a'), '名前'],
            '道を外へ向ける名前' => [ZipBuilder::single('../utf_ken_all.csv', 'a'), '名前'],
            '道を付けた名前' => [ZipBuilder::single('dir/utf_ken_all.csv', 'a'), '名前'],
            '暗号' => [ZipBuilder::make()->add('utf_ken_all.csv', 'a', flags: 1)->build(), '暗号'],
            '知らない圧縮の方式' => [ZipBuilder::make()->add('utf_ken_all.csv', 'a', method: 12)->build(), '方式'],
            'CRC の違い' => [ZipBuilder::make()->add('utf_ken_all.csv', 'abc', crc: crc32('abd'))->build(), 'CRC'],
            '名乗りより大きく広がる中身' => [ZipBuilder::make()->add('utf_ken_all.csv', str_repeat('a', 100_000), size: 10)->build(), '解けません'],
            '名乗りより小さい中身' => [ZipBuilder::make()->add('utf_ken_all.csv', 'abc', method: 0, size: 2)->build(), '長さ'],
            '名乗る大きさが 0 で中身がある' => [ZipBuilder::make()->add('utf_ken_all.csv', str_repeat('a', 1000), size: 0)->build(), '解けません'],
            'ZIP64 の名乗り' => [ZipBuilder::make()->add('utf_ken_all.csv', 'a', size: 0xFFFFFFFF)->build(), 'ZIP64'],
            '終わりの印がない' => [substr(ZipBuilder::single('utf_ken_all.csv', 'a'), 0, -22), '終わりの印'],
            'ZIP でない' => ['<html>not a zip</html>', '終わりの印'],
            '空' => ['', '終わりの印'],
            '途中で切れた' => [substr(ZipBuilder::single('utf_ken_all.csv', str_repeat('abc', 100)), 40), '読めません'],
        ];
    }

    #[DataProvider('refused')]
    public function test_信じられない_ZIP_は断る(string $zip, string $reason): void
    {
        try {
            ZipEntries::single($zip, 'utf_ken_all.csv');
            $this->fail('断らなかった');
        } catch (PostalCodesException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function test_名乗る展開後の大きさが上限を超えれば解かない(): void
    {
        $this->expectException(PostalCodesException::class);
        $this->expectExceptionMessage('大きすぎます');

        ZipEntries::single(ZipBuilder::single('utf_ken_all.csv', str_repeat('a', 101)), 'utf_ken_all.csv', maxBytes: 100);
    }

    public function test_上限は既定で_200MB(): void
    {
        $this->expectException(PostalCodesException::class);
        $this->expectExceptionMessage('大きすぎます');

        ZipEntries::single(ZipBuilder::make()->add('utf_ken_all.csv', 'a', size: 200 * 1024 * 1024 + 1)->build(), 'utf_ken_all.csv');
    }

    public function test_分割した_ZIP_は断る(): void
    {
        $zip = ZipBuilder::single('utf_ken_all.csv', 'a');
        // 終わりの印の「このディスクの番号」を 1 にする
        $zip = substr_replace($zip, pack('v', 1), strlen($zip) - 22 + 4, 2);

        $this->expectException(PostalCodesException::class);
        $this->expectExceptionMessage('分割');

        ZipEntries::single($zip, 'utf_ken_all.csv');
    }
}
