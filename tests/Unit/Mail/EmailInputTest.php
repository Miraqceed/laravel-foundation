<?php

namespace Miraqceed\Foundation\Tests\Unit\Mail;

use Miraqceed\Foundation\Mail\EmailInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 保存する値（normalize）と比べの形（canonical）。アドレスはすべて作り物
 */
class EmailInputTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function normalized(): array
    {
        return [
            '全角のローカル部' => ['ｔａｒｏ@example.co.jp', 'taro@example.co.jp'],
            '全角のドメイン' => ['taro@ｅｘａｍｐｌｅ.co.jp', 'taro@example.co.jp'],
            '全角の＠' => ['taro＠example.co.jp', 'taro@example.co.jp'],
            '全角の記号' => ['ｔａｒｏ＿ｘ－ｙ＋ｚ＠ｅｘ．ｊｐ', 'taro_x-y+z@ex.jp'],
            '前後の全角の空白' => ['　taro@example.co.jp　', 'taro@example.co.jp'],
            '前後のノーブレークスペースとゼロ幅' => ["\u{00A0}\u{200B}taro@example.co.jp\u{FEFF}", 'taro@example.co.jp'],
            'ドメインの句点' => ['taro@example。co.jp', 'taro@example.co.jp'],
            'ドメインの半角の句点' => ['taro@example｡co.jp', 'taro@example.co.jp'],
            '末尾の点' => ['taro@example.co.jp.', 'taro@example.co.jp'],
            '末尾の句点' => ['taro@example.co.jp。', 'taro@example.co.jp'],
            'ドメインは小文字・ローカル部は残す' => ['Taro.Yamada@Example.CO.JP', 'Taro.Yamada@example.co.jp'],
            '日本語のドメインは打ったまま' => ['taro@例え.JP', 'taro@例え.jp'],
            '非 ASCII の大文字は変えない' => ['taro@ÄBC.de', 'taro@Äbc.de'],
            'ローカル部の句点は変えない' => ['ta。ro@example.co.jp', 'ta。ro@example.co.jp'],
            '最後の @ より後がドメイン' => ['"a@b"@Example.COM', '"a@b"@example.com'],
            '@ のない値' => ['　ｔａｒｏ　', 'taro'],
            '空' => ['', ''],
        ];
    }

    #[DataProvider('normalized')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, EmailInput::normalize($input));
    }

    public function test_壊れた_UTF_8_は変えない(): void
    {
        $broken = "ｔａｒｏ\xff@example.co.jp";

        $this->assertSame($broken, EmailInput::normalize($broken));
        $this->assertSame($broken, EmailInput::canonical($broken));
    }

    /** @return array<string, array{string, string}> */
    public static function canonicals(): array
    {
        return [
            '全体を小文字' => ['Taro.Yamada@Example.CO.JP', 'taro.yamada@example.co.jp'],
            'Gmail の点と + 以降' => ['Taro.Yamada+news@Gmail.com', 'taroyamada@gmail.com'],
            'googlemail.com は gmail.com に' => ['taro.yamada@googlemail.com', 'taroyamada@gmail.com'],
            '全角の Gmail' => ['ｔａｒｏ．ｙ＋ａ＠ｇｍａｉｌ．ｃｏｍ', 'taroy@gmail.com'],
            'ほかのドメインの点と + は残す' => ['taro.yamada+news@example.co.jp', 'taro.yamada+news@example.co.jp'],
            '独自ドメイン（Google Workspace の形）は変えない' => ['taro.yamada@company.example', 'taro.yamada@company.example'],
            'gmail.com の下位のドメインは Gmail でない' => ['taro.y@mail.gmail.com', 'taro.y@mail.gmail.com'],
            '非 ASCII の大文字も小文字' => ['ÄBC@Äbc.de', 'äbc@äbc.de'],
            '@ のない値' => ['TARO', 'taro'],
        ];
    }

    #[DataProvider('canonicals')]
    public function test_canonical(string $input, string $expected): void
    {
        $this->assertSame($expected, EmailInput::canonical($input));
    }
}
