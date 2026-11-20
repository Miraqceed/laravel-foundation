<?php

namespace Miraqceed\Foundation\Tests\Unit\Japan;

use Miraqceed\Foundation\Japan\PostalCodeData;
use Miraqceed\Foundation\Japan\PostalCodesException;
use PHPUnit\Framework\TestCase;

/**
 * 日本郵便のデータから郵便番号の一覧を作る決まり。行は架空の会社・町・番号で作る（日本郵便のデータを試しに入れない）
 */
class PostalCodeDataTest extends TestCase
{
    /** 住所の郵便番号（1レコード1行、UTF-8 形式）の1行。列は日本郵便の説明の順 */
    public static function addr(string $code, string $pref, string $city, string $town): string
    {
        return "13101,\"100  \",\"{$code}\",\"トウキョウト\",\"チヨダク\",\"チヨダ\",\"{$pref}\",\"{$city}\",\"{$town}\",0,0,0,0,0,0";
    }

    /** 事業所の個別郵便番号の1行（UTF-8 で書く。読むときに Shift_JIS にする）。kind は「0」大口事業所・「1」私書箱 */
    public static function office(string $code, string $pref, string $city, string $town, string $detail, string $kind = '0'): string
    {
        return "13101,\"ジギョウショ\",\"架空の事業所\",\"{$pref}\",\"{$city}\",\"{$town}\",\"{$detail}\",\"{$code}\",\"100  \",\"架空郵便局\",{$kind},0,0";
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, list<list<string>>>
     */
    private static function offices(array $lines, string $eol = "\n"): array
    {
        return PostalCodeData::officeEntries(mb_convert_encoding(implode($eol, $lines), 'CP932', 'UTF-8'));
    }

    public function test_町名を番地の欄へ入れる値にする(): void
    {
        $map = PostalCodeData::addressEntries(self::addr('1000001', '東京都', '千代田区', '千代田')."\r\n");

        $this->assertSame([['東京都', '千代田区', '千代田']], $map['1000001']);
    }

    public function test_町域の注記は町名なしにする(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('0600000', '北海道', '架空市中央区', '以下に掲載がない場合'),
            self::addr('3060433', '茨城県', '架空郡境町', '境町の次に番地がくる場合'),
            self::addr('4090211', '山梨県', '架空郡小菅村', '小菅村の次に６６４番地以降がくる場合'),
            self::addr('7660002', '香川県', '架空郡琴平町', '琴平町の次に４２７番地以降がくる場合（川西）'),
            self::addr('9530000', '新潟県', '架空郡粟島浦村', '粟島浦村一円'),
        ]));

        $this->assertSame([['北海道', '架空市中央区', '']], $map['0600000']);
        $this->assertSame([['茨城県', '架空郡境町', '']], $map['3060433']);
        $this->assertSame([['山梨県', '架空郡小菅村', '']], $map['4090211'], '「の次に〜がくる場合」の形の注記も町名なし');
        $this->assertSame([['香川県', '架空郡琴平町', '']], $map['7660002']);
        $this->assertSame([['新潟県', '架空郡粟島浦村', '']], $map['9530000']);
    }

    public function test_範囲や並びで書いた町域は町名なしにする(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('0287901', '岩手県', '架空郡洋野町', '種市第８地割～第１４地割'),
            self::addr('0295502', '岩手県', '架空郡西和賀町', '樺沢１６地割、樺沢１７地割'),
        ]));

        $this->assertSame([['岩手県', '架空郡洋野町', '']], $map['0287901']);
        $this->assertSame([['岩手県', '架空郡西和賀町', '']], $map['0295502']);
    }

    public function test_一円という町名そのものは注記でない(): void
    {
        $map = PostalCodeData::addressEntries(self::addr('5220317', '滋賀県', '架空郡多賀町', '一円'));

        $this->assertSame([['滋賀県', '架空郡多賀町', '一円']], $map['5220317']);
    }

    public function test_括弧の中は入れず_括弧を落として同じになる町名は1つにする(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('0600042', '北海道', '架空市中央区', '大通西（１～１９丁目）'),
            self::addr('0600042', '北海道', '架空市中央区', '大通西（２０～２８丁目）'),
        ]));

        $this->assertSame([['北海道', '架空市中央区', '大通西']], $map['0600042']);
    }

    public function test_1つの番号に町名が複数あれば_すべてを候補にする(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('4980000', '愛知県', '架空市', '以下に掲載がない場合'),
            self::addr('4980000', '三重県', '架空郡木曽岬町', '以下に掲載がない場合'),
            self::addr('0010000', '北海道', '架空市', '甲町'),
            self::addr('0010000', '北海道', '架空市', '乙町'),
        ]));

        $this->assertSame([['愛知県', '架空市', ''], ['三重県', '架空郡木曽岬町', '']], $map['4980000']);
        $this->assertSame([['北海道', '架空市', '甲町'], ['北海道', '架空市', '乙町']], $map['0010000']);
    }

    public function test_全角の英数字とハイフンは半角にする(): void
    {
        $map = PostalCodeData::addressEntries(self::addr('0010001', '北海道', '架空市', '北１条西Ａ－ｂ'));

        $this->assertSame([['北海道', '架空市', '北1条西A-b']], $map['0010001']);
    }

    public function test_高層ビルの階ごとの番号は_町名と_ビルの名前と階に分ける(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('1630000', '東京都', '新宿区', '西新宿（次のビルを除く）'),
            self::addr('1636001', '東京都', '新宿区', '西新宿架空タワー（地階・階層不明）'),
            self::addr('1636002', '東京都', '新宿区', '西新宿架空タワー（１階）'),
            self::addr('1636010', '東京都', '新宿区', '西新宿架空タワー（高層棟）（１２階）'),
        ]));

        $this->assertSame([['東京都', '新宿区', '西新宿']], $map['1630000']);
        $this->assertSame([['東京都', '新宿区', '西新宿', '架空タワー']], $map['1636001']);
        $this->assertSame([['東京都', '新宿区', '西新宿', '架空タワー 1階']], $map['1636002']);
        $this->assertSame([['東京都', '新宿区', '西新宿', '架空タワー 12階']], $map['1636010']);
    }

    public function test_高層ビルの町名は_同じ市区町村の_いちばん長い町名を使う(): void
    {
        $map = PostalCodeData::addressEntries(implode("\n", [
            self::addr('1630000', '東京都', '新宿区', '西新宿（次のビルを除く）'),
            self::addr('1630001', '東京都', '新宿区', '西新宿二丁目（次のビルを除く）'),
            self::addr('1700000', '東京都', '豊島区', '西新宿二丁目架空（次のビルを除く）'),
            self::addr('1636002', '東京都', '新宿区', '西新宿二丁目架空タワー（１階）'),
        ]));

        $this->assertSame([['東京都', '新宿区', '西新宿二丁目', '架空タワー 1階']], $map['1636002']);
    }

    public function test_町名の分からない階の番号は_括弧を落とした名前を番地の欄へ入れる(): void
    {
        $map = PostalCodeData::addressEntries(self::addr('1636003', '東京都', '新宿区', '架空タワー（３階）'));

        $this->assertSame([['東京都', '新宿区', '架空タワー']], $map['1636003']);
    }

    public function test_事業所の番号は番地まで入れ_私書箱は入れない(): void
    {
        $map = self::offices([
            self::office('1008799', '東京都', '千代田区', '大手町', '９丁目９－９'),
            self::office('1008798', '東京都', '千代田区', '大手町', '９丁目９－９', '1'),
        ], "\r\n");

        $this->assertSame([['東京都', '千代田区', '大手町9丁目9-9']], $map['1008799']);
        $this->assertArrayNotHasKey('1008798', $map);
    }

    public function test_事業所の町名の括弧と注記も入れない(): void
    {
        $map = self::offices([
            self::office('1008797', '東京都', '千代田区', '大手町（架空）', '１－１'),
            self::office('1008796', '東京都', '千代田区', '以下に掲載がない場合', '２－２'),
        ]);

        $this->assertSame([['東京都', '千代田区', '大手町1-1']], $map['1008797']);
        $this->assertSame([['東京都', '千代田区', '2-2']], $map['1008796']);
    }

    public function test_事業所の番地は頭の番号だけを入れ_後ろの建物名・会社名は入れない(): void
    {
        $map = self::offices([
            self::office('1008701', '東京都', '千代田区', '大手町', '９丁目９－９架空ビル５Ｆ'),
            self::office('1008702', '東京都', '千代田区', '大手町', '１－１（株）架空商事４階'),
            self::office('1008703', '東京都', '千代田区', '大手町', '１丁目３番２号（架空リース（株）内）'),
            self::office('1008704', '東京都', '千代田区', '大手町', '５丁目地先'),
            self::office('1008705', '東京都', '千代田区', '大手町', '字東町１２３'),
            self::office('1008707', '東京都', '千代田区', '大手町', '番外地'),
            self::office('1008708', '東京都', '千代田区', '大手町', '３地割－'),
        ]);

        $this->assertSame([['東京都', '千代田区', '大手町']], $map['1008707'], '数字の前の「番」は番号でない');
        $this->assertSame([['東京都', '千代田区', '大手町9丁目9-9']], $map['1008701']);
        $this->assertSame([['東京都', '千代田区', '大手町1-1']], $map['1008702']);
        $this->assertSame([['東京都', '千代田区', '大手町1丁目3番2号']], $map['1008703']);
        $this->assertSame([['東京都', '千代田区', '大手町5丁目']], $map['1008704']);
        $this->assertSame([['東京都', '千代田区', '大手町']], $map['1008705']);
        $this->assertSame([['東京都', '千代田区', '大手町3地割']], $map['1008708'], '終わりのハイフンは落とす');
    }

    public function test_事業所の名前に引用符で囲んだカンマや二重の引用符があっても_列を読み違えない(): void
    {
        $map = self::offices([
            '13101,"ジギョウショ","架空,事業所","東京都","千代田区","大手町","１－１","1008706","100  ","架空郵便局",0,0,0',
            '13101,"ジギョウショ","架空""事業所""","東京都","千代田区","大手町","２－２","1008709","100  ","架空郵便局",0,0,0',
        ]);

        $this->assertSame([['東京都', '千代田区', '大手町1-1']], $map['1008706']);
        $this->assertSame([['東京都', '千代田区', '大手町2-2']], $map['1008709']);
    }

    public function test_列の数が違う行があれば止める(): void
    {
        foreach ([
            fn () => self::offices(['13101,"ジギョウショ","架空","東京都"']),
            fn () => PostalCodeData::addressEntries(self::addr('1000001', '東京都', '千代田区', '千代田')."\n".'13101,"100  ","1000002","トウキョウト"'),
        ] as $read) {
            try {
                $read();
                $this->fail('止まらなかった');
            } catch (PostalCodesException $e) {
                $this->assertStringContainsString('列の数', $e->getMessage());
            }
        }
    }

    public function test_番号が7桁の数字でない行があれば止める(): void
    {
        foreach (['../1234', 'abc1234', '100001', '10000011', '１０００００１', '1000001 ', ''] as $code) {
            foreach ([
                fn () => PostalCodeData::addressEntries(self::addr('1000001', '東京都', '千代田区', '千代田')."\n".self::addr($code, '東京都', '千代田区', '架空町')),
                fn () => self::offices([self::office('1008799', '東京都', '千代田区', '大手町', '１'), self::office($code, '東京都', '千代田区', '大手町', '１', '1')]),
            ] as $read) {
                try {
                    $read();
                    $this->fail("{$code} で止まらなかった");
                } catch (PostalCodesException $e) {
                    $this->assertSame('2行目の郵便番号が7桁の数字ではありません。日本郵便のデータの形を確かめてください', $e->getMessage());
                }
            }
        }
    }

    public function test_ファイルに分けるときも_7桁の数字でない番号は止める(): void
    {
        $this->expectException(PostalCodesException::class);

        PostalCodeData::shardFiles(['../1234' => [['東京都', '千代田区', '架空町']]]);
    }

    public function test_読めない文字があれば止める(): void
    {
        foreach ([
            fn () => PostalCodeData::addressEntries(self::addr('1000001', '東京都', '千代田区', "千代\xFF田")),
            fn () => PostalCodeData::officeEntries("13101,\"\x81\""),
        ] as $read) {
            try {
                $read();
                $this->fail('止まらなかった');
            } catch (PostalCodesException $e) {
                $this->assertStringContainsString('読めません', $e->getMessage());
            }
        }
    }

    public function test_先頭の_BOM_と空の行は読まない(): void
    {
        $map = PostalCodeData::addressEntries("\xEF\xBB\xBF".self::addr('1000001', '東京都', '千代田区', '千代田')."\r\n\r\n");

        $this->assertSame(['1000001'], array_map('strval', array_keys($map)));
    }

    public function test_住所と事業所を合わせ_同じ番号は住所の候補を先にし_同じ候補は1つにする(): void
    {
        $merged = PostalCodeData::merge(
            ['1000001' => [['東京都', '千代田区', '千代田']]],
            ['1000001' => [['東京都', '千代田区', '千代田1-1'], ['東京都', '千代田区', '千代田']], '1008799' => [['東京都', '千代田区', '大手町1']]],
        );

        $this->assertSame(['1000001', '1008799'], array_map('strval', array_keys($merged)));
        $this->assertSame([['東京都', '千代田区', '千代田'], ['東京都', '千代田区', '千代田1-1']], $merged['1000001']);
    }

    public function test_上3桁ごとのファイルにし_1行に1つの番号を番号の順で書く(): void
    {
        $files = PostalCodeData::shardFiles([
            '1000002' => [['東京都', '千代田区', '架空/外苑']],
            '1000001' => [['東京都', '千代田区', '千代田']],
            '0600000' => [['北海道', '架空市中央区', '']],
        ]);

        $this->assertSame(['060.json', '100.json'], array_keys($files));
        $this->assertSame("{\n\"1000001\":[[\"東京都\",\"千代田区\",\"千代田\"]],\n\"1000002\":[[\"東京都\",\"千代田区\",\"架空/外苑\"]]\n}\n", $files['100.json']);
        $this->assertSame(['0600000' => [['北海道', '架空市中央区', '']]], json_decode($files['060.json'], true));
    }

    public function test_データの指紋はファイルの名前と中身から決まり_名前の順によらない(): void
    {
        $a = PostalCodeData::fingerprint(['100.json' => 'a', '060.json' => 'b']);

        $this->assertSame($a, PostalCodeData::fingerprint(['060.json' => 'b', '100.json' => 'a']));
        $this->assertNotSame($a, PostalCodeData::fingerprint(['060.json' => 'b', '100.json' => 'c']));
        // 名前の順に「名前 NUL 中身 NUL」を並べた SHA-256（NUL は \x00 と書く。"\0100" は8進の \010 と読まれる）
        $this->assertSame(hash('sha256', "060.json\x00b\x00100.json\x00a\x00"), $a);
    }
}
