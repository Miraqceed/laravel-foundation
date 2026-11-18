<?php

namespace Miraqceed\Foundation\Tests\Unit\Files;

use Miraqceed\Foundation\Files\FileName;
use PHPUnit\Framework\TestCase;

class FileNameTest extends TestCase
{
    public function test_part_は使えない字を_にし前後の空白を除く(): void
    {
        $this->assertSame('a_b_c_d_e_f_g_h_i_j_k', FileName::part(" a\\b/c:d*e?f\"g<h>i|j\x01k "));
        // 前後の空白を除くのは、字を _ にした後（終わりのタブは _ として残る）
        $this->assertSame('k_', FileName::part("k\t"));
    }

    public function test_part_は書記素の切れ目で切る(): void
    {
        // 日本語は1文字3バイト。200バイトの中に66文字（198バイト）
        $this->assertSame(str_repeat('あ', 66), FileName::part(str_repeat('あ', 100)));

        // ちょうど200バイトは切らない
        $exact = str_repeat('a', 200);
        $this->assertSame($exact, FileName::part($exact));

        // ZWJ でつないだ絵文字（👨‍👩‍👧 は18バイト）の途中で切らない
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $this->assertSame(str_repeat('a', 190), FileName::part(str_repeat('a', 190).$family));
        $this->assertSame(str_repeat('a', 182).$family, FileName::part(str_repeat('a', 182).$family.'b'));

        // 文字と結合記号（か＋゛）の間で切らない
        $ga = "\u{304B}\u{3099}";
        $this->assertSame(str_repeat('a', 197), FileName::part(str_repeat('a', 197).$ga));
    }

    public function test_part_は壊れた_UTF8_を直す(): void
    {
        // 直した字（?）も使えない字なので _ になる
        $this->assertSame('a_b', FileName::part("a\xFFb"));
    }

    public function test_name_は拡張子を残して収める(): void
    {
        $name = FileName::name(str_repeat('名', 100), 'csv');

        $this->assertStringEndsWith('.csv', $name);
        $this->assertLessThanOrEqual(200, strlen($name));
        $this->assertSame(str_repeat('名', 65).'.csv', $name);
    }

    public function test_name_は空や点だけなら_file_予約の名前は前に_を付ける(): void
    {
        $this->assertSame('file.csv', FileName::name('', 'csv'));
        $this->assertSame('file.csv', FileName::name(' . . ', 'csv'));
        $this->assertSame('file', FileName::name('', ''));
        $this->assertSame('_CON.txt', FileName::name('CON', 'txt'));
        $this->assertSame('_lpt1', FileName::name('lpt1', ''));
        $this->assertSame('CONSOLE.txt', FileName::name('CONSOLE', 'txt'));
        // 終わりの点と空白を除く
        $this->assertSame('memo.txt', FileName::name('memo. ', 'txt'));
    }

    public function test_forUpload_は道を除き_拡張子を残す(): void
    {
        $this->assertSame('passwd', FileName::forUpload('../../etc/passwd'));
        $this->assertSame('a.png', FileName::forUpload('C:\\fake\\a.png'));
        $this->assertSame('file', FileName::forUpload('..'));
        $this->assertSame('.htaccess', FileName::forUpload('.htaccess'));
    }

    public function test_forUpload_は長い名前を列に収める(): void
    {
        $name = FileName::forUpload(str_repeat('長', 300).'.xlsx');

        $this->assertLessThanOrEqual(200, strlen($name));
        $this->assertLessThanOrEqual(255, mb_strlen($name));
        $this->assertStringEndsWith('.xlsx', $name);
    }

    public function test_forUpload_は_NFC_にそろえ壊れた_UTF8_を直す(): void
    {
        // macOS の名前：「が」が「か」＋結合の濁点（NFD）
        $this->assertSame("\u{304C}.pdf", FileName::forUpload("\u{304B}\u{3099}.pdf"));
        $this->assertSame('a_b.pdf', FileName::forUpload("a\xFFb.pdf"));
    }

    public function test_ascii_は_ASCII_の字を残し_意味がなければ代わりを使う(): void
    {
        $this->assertSame('report-2026.csv', FileName::ascii('report-2026.csv', 'list'));
        $this->assertSame('list.csv', FileName::ascii('一覧.csv', 'list'));
        $this->assertSame('__2026.csv', FileName::ascii('請求2026.csv', 'list'));
        $this->assertSame('download.csv', FileName::ascii('一覧.csv', '一覧'));
        $this->assertSame('a_b.csv', FileName::ascii('a%b.csv', 'list'));
    }

    public function test_disposition_は_slash_で例外にならず_両方の名前を付ける(): void
    {
        $this->assertSame(
            "attachment; filename=list.csv; filename*=utf-8''".rawurlencode('一覧.csv'),
            FileName::disposition('一覧.csv', 'list'),
        );
        $this->assertSame('attachment; filename=a_b.csv', FileName::disposition('a/b.csv'));
        $this->assertSame('inline; filename=photo.png', FileName::disposition('photo.png', inline: true));

        $long = FileName::disposition(str_repeat('名', 300).'.pdf');
        $this->assertStringContainsString('filename=download.pdf', $long);
        $this->assertStringContainsString(rawurlencode(str_repeat('名', 65).'.pdf'), $long);
    }
}
