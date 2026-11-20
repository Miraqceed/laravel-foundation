<?php

namespace Miraqceed\Foundation\Tests\Unit\Japan;

use Miraqceed\Foundation\Japan\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 電話番号を区切る。桁の数で推測せず、入力の区切り・番号の種類・市外局番の表（パッケージに入れた area-codes.json）で決める
 */
class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function numbers(): array
    {
        return [
            '市外局番が2桁（03）' => ['0312345678', '03-1234-5678'],
            '市外局番が2桁（06）' => ['0612345678', '06-1234-5678'],
            '市外局番が3桁（045）' => ['0451234567', '045-123-4567'],
            '市外局番が4桁（0465）' => ['0465123456', '0465-12-3456'],
            '市外局番が5桁（01267）' => ['0126712345', '01267-1-2345'],
            // 先頭の数字が同じでも、6桁で市外局番の桁が変わる
            '04 の後の6桁で決まる市外局番が2桁の番号（04-29xx）' => ['0429201234', '04-2920-1234'],
            '04 の後の6桁で決まる市外局番が3桁の番号（042-6xx）' => ['0426201234', '042-620-1234'],
            '携帯電話（090）' => ['09012345678', '090-1234-5678'],
            '携帯電話（080）' => ['08012345678', '080-1234-5678'],
            '携帯電話（070）' => ['07012345678', '070-1234-5678'],
            '携帯電話（060）' => ['06012345678', '060-1234-5678'],
            'IP 電話（050）' => ['05012345678', '050-1234-5678'],
            'M2M（020）' => ['02012345678', '020-1234-5678'],
            'フリーダイヤル（0120）は 4-3-3' => ['0120988372', '0120-988-372'],
            'ナビダイヤル（0570）は 4-3-3' => ['0570123456', '0570-123-456'],
            '0990 は 4-3-3' => ['0990123456', '0990-123-456'],
            'フリーダイヤル（0800）は 4-3-4' => ['08001234567', '0800-123-4567'],
            // 書いた区切りを使う（0120 を「0120-98-8372」と書く会社がある）
            '入力した区切りのまま' => ['0120-98-8372', '0120-98-8372'],
            '全角の数字とハイフン' => ['０３－１２３４－５６７８', '03-1234-5678'],
            '全角の括弧' => ['０３（１２３４）５６７８', '03-1234-5678'],
            '長音の記号で区切った' => ['03ー1234ー5678', '03-1234-5678'],
            'ダッシュで区切った' => ['03—1234—5678', '03-1234-5678'],
            '括弧で区切った' => ['03(1234)5678', '03-1234-5678'],
            '空白で区切った' => ['03 1234 5678', '03-1234-5678'],
            '全角の空白で区切った' => ['03　1234　5678', '03-1234-5678'],
            '前後の空白' => [' 0312345678 ', '03-1234-5678'],
            // 区切りが2つ（市外局番だけ区切った）なら、数字から区切り直す
            '区切りが足りない' => ['03-12345678', '03-1234-5678'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_区切った形にする(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::format($input));
        $this->assertNull(PhoneNumber::problem($input));
    }

    /** @return array<string, array{?string}> */
    public static function shapes(): array
    {
        return [
            '桁が足りない' => ['031234567'],
            '区切った桁が足りない' => ['03-1234-567'],
            '桁が多い' => ['031234567890'],
            '0 で始まらない' => ['3312345678'],
            '内線を書いた' => ['03-1234-5678 内線12'],
            '国番号' => ['+81-3-1234-5678'],
            '英字' => ['03-1234-567a'],
            'ほかの文字の数字' => ['٠٣١٢٣٤٥٦٧٨'],
            '後ろに改行と文字' => ["0312345678\nx"],
            '壊れた UTF-8' => ["0312345678\xFF"],
            '空' => [''],
            'なし' => [null],
        ];
    }

    #[DataProvider('shapes')]
    public function test_番号の形でなければ_shape(?string $input): void
    {
        $this->assertNull(PhoneNumber::format($input));
        $this->assertSame('shape', PhoneNumber::problem($input));
    }

    /** @return array<string, array{string}> */
    public static function groupings(): array
    {
        return [
            '割り当てのない番号（市内局番が1で始まる）' => ['0980123456'],
            '11桁で種類の決まりがない' => ['01012345678'],
        ];
    }

    #[DataProvider('groupings')]
    public function test_形は合うが区切りが決まらなければ_grouping(string $input): void
    {
        $this->assertNull(PhoneNumber::format($input));
        $this->assertSame('grouping', PhoneNumber::problem($input));
    }

    public function test_区切りが決まらない番号も_書いた区切りが形に合えば使う(): void
    {
        $this->assertSame('0980-12-3456', PhoneNumber::format('0980-12-3456'));
    }

    public function test_市外局番の表に_出典と加工して作成を書いている(): void
    {
        $table = json_decode((string) file_get_contents(PhoneNumber::AREA_CODES), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('総務省ホームページ', $table['source']);
        $this->assertStringContainsString('https://www.soumu.go.jp/', $table['source']);
        $this->assertStringContainsString('を加工して作成', $table['source']);
        $this->assertNotEmpty($table['asOf']);
        // 前方一致の表：0 から始まる2〜6桁 → 0 を含めた市外局番の桁（2〜5）
        $this->assertGreaterThan(500, count($table['prefixes']));
        foreach ($table['prefixes'] as $prefix => $length) {
            $this->assertMatchesRegularExpression('/\A0[0-9]{1,5}\z/', (string) $prefix);
            $this->assertContains($length, [2, 3, 4, 5]);
        }
    }
}
