<?php

namespace Miraqceed\Foundation\Tests\Feature\Japan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Miraqceed\Foundation\Japan\PostalAddressBook;
use Miraqceed\Foundation\Japan\PostalCode;
use Miraqceed\Foundation\Japan\PostalCodesException;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 郵便番号の読み方と、置いた一覧（作り物）から住所を引くこと
 */
class PostalAddressBookTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/foundation-postal-'.bin2hex(random_bytes(6)).'/postal-codes';
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/100.json', "{\n".implode(",\n", [
            '"1000001":[["東京都","千代田区","千代田"]]',
            '"1000099":[["東京都","千代田区","丸の内","架空ビル 1階"]]',
        ])."\n}\n");
        file_put_contents($this->directory.'/001.json', "{\n\"0010000\":[[\"北海道\",\"架空市\",\"甲町\"],[\"北海道\",\"架空市\",\"乙町\"]]\n}\n");
        file_put_contents($this->directory.'/source.json', "{\n  \"updated\": \"2026-10-23\",\n  \"codes\": 3\n}\n");
        config(['foundation.japan.postal_codes.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->directory));

        parent::tearDown();
    }

    /** @return array<string, array{?string, ?string}> */
    public static function postalCodes(): array
    {
        return [
            '区切りなし' => ['1000001', '100-0001'],
            '区切りあり' => ['100-0001', '100-0001'],
            '〒と全角' => ['〒１００－０００１', '100-0001'],
            '長音の記号' => ['〒１００ー０００１', '100-0001'],
            '空白' => [' 100 0001 ', '100-0001'],
            '全角の空白' => ['100　0001', '100-0001'],
            '6桁' => ['100001', null],
            '8桁' => ['10000011', null],
            '数字でない' => ['東京都', null],
            'ほかの文字の数字' => ['١٠٠٠٠٠١', null],
            '後ろに改行' => ["1000001\n内線", null],
            '壊れた UTF-8' => ["100\xFF0001", null],
            '空' => ['', null],
            'なし' => [null, null],
        ];
    }

    #[DataProvider('postalCodes')]
    public function test_郵便番号を_3桁と4桁で区切る(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PostalCode::format($input));
    }

    #[DataProvider('inputs')]
    public function test_郵便番号の書き方によらず引ける(string $input): void
    {
        $this->assertSame([['prefecture' => '東京都', 'city' => '千代田区', 'street' => '千代田']], app(PostalAddressBook::class)->lookup($input));
    }

    /** @return array<string, array{string}> */
    public static function inputs(): array
    {
        return [
            '区切りあり' => ['100-0001'],
            '区切りなし' => ['1000001'],
            '全角' => ['１００－０００１'],
            '〒付き' => ['〒100-0001'],
            '空白' => [' 100 0001 '],
        ];
    }

    public function test_町名が複数ある番号は候補をすべて返す(): void
    {
        $this->assertSame([
            ['prefecture' => '北海道', 'city' => '架空市', 'street' => '甲町'],
            ['prefecture' => '北海道', 'city' => '架空市', 'street' => '乙町'],
        ], app(PostalAddressBook::class)->lookup('001-0000'));
    }

    public function test_建物名のある番号は建物名も返す(): void
    {
        $this->assertSame(
            [['prefecture' => '東京都', 'city' => '千代田区', 'street' => '丸の内', 'building' => '架空ビル 1階']],
            app(PostalAddressBook::class)->lookup('1000099'),
        );
    }

    /** @return array<string, array{?string}> */
    public static function notFound(): array
    {
        return [
            '同じ上3桁のファイルにない番号' => ['100-0002'],
            '上3桁のファイルがない番号' => ['999-9999'],
            '桁が足りない' => ['100-001'],
            '桁が多い' => ['100-00011'],
            '数字でない' => ['abc-defg'],
            'ファイルの名前を外へ向ける' => ['../-0001'],
            '空' => [''],
            'なし' => [null],
        ];
    }

    #[DataProvider('notFound')]
    public function test_見つからない番号と郵便番号でない入力は空(?string $input): void
    {
        $this->assertSame([], app(PostalAddressBook::class)->lookup($input));
    }

    public function test_置き場所がなければ空で_古さも見ない(): void
    {
        $book = new PostalAddressBook($this->directory.'-none');

        $this->assertSame([], $book->lookup('100-0001'));
        $this->assertNull($book->source());
        $this->assertNull($book->updatedOn());
        $this->assertFalse($book->isStale(Carbon::parse('2099-01-01')));
    }

    public function test_置き場所の既定は_resources_postal_codes(): void
    {
        config(['foundation.japan.postal_codes.path' => null]);

        $this->assertSame(resource_path('postal-codes'), PostalAddressBook::configuredPath());
        $this->assertSame(resource_path('postal-codes'), app(PostalAddressBook::class)->directory());
    }

    public function test_データの更新日から3か月を過ぎたら古い(): void
    {
        $book = app(PostalAddressBook::class);

        $this->assertSame('2026-10-23', $book->updatedOn());
        $this->assertFalse($book->isStale(Carbon::parse('2027-01-23 23:59:59', 'Asia/Tokyo')));
        $this->assertTrue($book->isStale(Carbon::parse('2027-01-24 00:00:00', 'Asia/Tokyo')));
        // 時間帯の違う時刻でも、日本時間で比べる
        $this->assertTrue($book->isStale(Carbon::parse('2027-01-23 15:00:00', 'UTC')));
    }

    public function test_読めない_source_json_は投げる(): void
    {
        file_put_contents($this->directory.'/source.json', '{"updated": "昨日"}');

        $this->expectException(PostalCodesException::class);

        app(PostalAddressBook::class)->source();
    }
}
