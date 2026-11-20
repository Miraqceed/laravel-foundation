<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Miraqceed\Foundation\DeployCheck\Checks\PostalCodes;
use Miraqceed\Foundation\DeployCheck\Runner;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * postal_codes：一覧を置いていなければ skip、更新日から3か月の日の終わりまで ok、過ぎれば later（--strict で失敗）、読めなければ FAIL
 */
class PostalCodesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/foundation-postal-'.bin2hex(random_bytes(6));
        config(['foundation.japan.postal_codes.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function place(string $source): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/source.json', $source);
    }

    public function test_既定の調べに入っている(): void
    {
        $this->assertContains(PostalCodes::class, Runner::DEFAULTS);
        $this->assertSame('postal_codes', (new PostalCodes)->id());
    }

    public function test_一覧を置いていなければ_skip(): void
    {
        $this->assertSame(Status::Skip, (new PostalCodes)->run()->status);

        // ディレクトリだけあっても、source.json がなければ使っていない
        File::ensureDirectoryExists($this->directory);
        $this->assertSame(Status::Skip, (new PostalCodes)->run()->status);
    }

    public function test_更新日から3か月の日の終わりまでは_ok_過ぎれば_later(): void
    {
        $this->place('{"updated": "2026-10-23", "codes": 1}');

        $this->travelTo(Carbon::parse('2027-01-23 23:59:59', 'Asia/Tokyo'));
        $ok = (new PostalCodes)->run();
        $this->assertSame(Status::Ok, $ok->status);
        $this->assertStringContainsString('2026-10-23', (string) $ok->detail);

        $this->travelTo(Carbon::parse('2027-01-24 00:00:00', 'Asia/Tokyo'));
        $later = (new PostalCodes)->run();
        $this->assertSame(Status::Later, $later->status);
        $this->assertStringContainsString('foundation:postal-codes', (string) $later->detail);
    }

    public function test_古い一覧は_strict_のときだけ失敗にする(): void
    {
        $this->place('{"updated": "2020-01-01", "codes": 1}');
        $others = array_values(array_filter(
            array_map(fn (string $check) => (new $check)->id(), Runner::DEFAULTS),
            fn (string $id) => $id !== 'postal_codes',
        ));
        config(['foundation.deploy_check.skip' => $others]);

        $this->assertSame(0, Artisan::call('foundation:deploy-check'));
        $this->assertMatchesRegularExpression('/^\[later\]\s+postal_codes\s+/m', Artisan::output());
        $this->assertSame(1, Artisan::call('foundation:deploy-check', ['--strict' => true]));
    }

    /** @return array<string, array{string}> */
    public static function broken(): array
    {
        return [
            'JSON でない' => ['{'],
            '更新日がない' => ['{"codes": 1}'],
            '更新日の形が違う' => ['{"updated": "昨日"}'],
            'ありえない日付' => ['{"updated": "2026-13-45"}'],
        ];
    }

    #[DataProvider('broken')]
    public function test_読めない_source_json_は_FAIL(string $source): void
    {
        $this->place($source);

        $result = (new PostalCodes)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('foundation:postal-codes', (string) $result->detail);
    }
}
