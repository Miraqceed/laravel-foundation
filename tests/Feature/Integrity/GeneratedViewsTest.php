<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Blade;
use Miraqceed\Foundation\DeployCheck\Checks\Integrity as IntegrityCheck;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Integrity;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 部品が作る画面の場所（generated_views）：Livewire 4 の部品のフォルダーと、Blade::render()・インラインの部品が置く元。
 * 許さなければ名前で出し、許せば出さず、コンパイルした画面のフォルダーの下でない場所は断る。許す値も一覧の設定に入る
 */
class GeneratedViewsTest extends TestCase
{
    use IntegrityTree;

    private const VIEWS = 'storage/framework/views';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    /** Livewire 4 が画面のフォルダーの下に置く形（作り物の中身） */
    private function livewireFolder(): void
    {
        $this->writeFile(self::VIEWS.'/livewire/.gitignore', "*\n!.gitignore\n");
        $this->writeFile(self::VIEWS.'/livewire/views/375e6f5e.blade.php', "<div>{{ \$count }}</div>\n");
        $this->writeFile(self::VIEWS.'/livewire/classes/375e6f5e.php', "<?php\nreturn new class {};\n");
    }

    /** 本物の Blade::render() で、元（<hash>.blade.php）とその画面を置く */
    private function rendered(): string
    {
        $this->assertSame('<p>1</p>', Blade::render('<p>{{ $a }}</p>', ['a' => 1]));
        $sources = glob($this->root.'/'.self::VIEWS.'/*.blade.php');
        $this->assertCount(1, $sources);

        return self::VIEWS.'/'.basename($sources[0]);
    }

    /** @return list<string> */
    private function allFindings(): array
    {
        $report = $this->check();

        return [...$this->findings($report), ...$this->gaps($report)];
    }

    public function test_without_the_setting_the_generated_views_are_named(): void
    {
        $this->snapshot();
        $this->livewireFolder();
        $source = $this->rendered();

        $list = $this->allFindings();

        $this->assertContains('storage-php:'.self::VIEWS.'/livewire/classes/375e6f5e.php', $list);
        $this->assertContains('storage-php:'.self::VIEWS.'/livewire/views/375e6f5e.blade.php', $list);
        $this->assertContains('view-stray:'.self::VIEWS.'/livewire/.gitignore', $list);
        $this->assertContains('view-without-source:'.$source, $list);
        $this->assertCount(1, array_filter($list, fn (string $line) => str_starts_with($line, 'view-without-source:') && $line !== 'view-without-source:'.$source));
    }

    public function test_allowed_places_are_not_named_and_the_rendered_view_is_still_compared(): void
    {
        config(['foundation.integrity.generated_views' => [self::VIEWS.'/livewire', self::VIEWS.'/*.blade.php']]);
        $this->assertSame(0, $this->snapshot());
        $this->livewireFolder();
        $this->rendered();

        $this->assertSame([], $this->allFindings());

        // 許した元から作った画面も、元とコンパイルし直して比べる
        foreach (glob($this->root.'/'.self::VIEWS.'/*.php') as $file) {
            if (! str_ends_with($file, '.blade.php')) {
                file_put_contents($file, str_replace('<p>', '<p onclick=x>', (string) file_get_contents($file)));
                $changed = self::VIEWS.'/'.basename($file);
            }
        }
        $this->assertContains('view-content:'.$changed, $this->allFindings());
    }

    public function test_a_php_file_outside_the_allowed_folder_is_still_named(): void
    {
        config(['foundation.integrity.generated_views' => [self::VIEWS.'/livewire']]);
        $this->snapshot();
        $this->livewireFolder();
        $this->writeFile(self::VIEWS.'/livewire-other/x.php', '<?php');
        $this->writeFile('storage/app/livewire/x.php', '<?php');

        $list = $this->allFindings();

        $this->assertContains('storage-php:'.self::VIEWS.'/livewire-other/x.php', $list);
        $this->assertContains('storage-php:storage/app/livewire/x.php', $list);
        $this->assertNotContains('storage-php:'.self::VIEWS.'/livewire/classes/375e6f5e.php', $list);
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedPlaces(): iterable
    {
        yield 'the compiled views folder itself' => [self::VIEWS];
        yield 'outside the compiled views folder' => ['storage/app/livewire'];
        yield 'a path that climbs out' => [self::VIEWS.'/../../app'];
        yield 'another wildcard' => [self::VIEWS.'/*.php'];
        yield 'a wildcard folder' => [self::VIEWS.'/*/classes'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedPlaces')]
    public function test_places_outside_the_compiled_views_folder_are_refused_and_change_nothing(string $place): void
    {
        config(['foundation.integrity.generated_views' => [$place]]);
        $this->snapshot();
        $this->livewireFolder();
        $this->writeFile('storage/app/livewire/x.php', '<?php');
        $source = $this->rendered();

        $list = $this->allFindings();

        $this->assertContains('generated-views-refused:'.$place, $list);
        $this->assertContains('storage-php:'.self::VIEWS.'/livewire/classes/375e6f5e.php', $list);
        $this->assertContains('storage-php:storage/app/livewire/x.php', $list);
        $this->assertContains('view-without-source:'.$source, $list);
    }

    public function test_the_allowed_places_are_in_the_snapshot_settings_and_the_strictest_wins(): void
    {
        $this->snapshot();
        config(['foundation.integrity.generated_views' => [self::VIEWS.'/livewire']]);
        $this->livewireFolder();

        $report = $this->check();

        $this->assertGap($report, Gap::SettingsDiffer);
        $this->assertContains('storage-php:'.self::VIEWS.'/livewire/classes/375e6f5e.php', $this->findings($report));

        $this->snapshot();
        config(['foundation.integrity.generated_views' => []]);
        $report = $this->check();
        $this->assertGap($report, Gap::SettingsDiffer);
        $this->assertContains('storage-php:'.self::VIEWS.'/livewire/classes/375e6f5e.php', $this->findings($report));
    }

    public function test_the_deploy_check_fails_on_a_refused_place_and_passes_an_allowed_one(): void
    {
        $this->app->instance(ViewsProcess::class, new ViewsProcess(available: true));
        config(['foundation.monitoring.enabled' => true, 'foundation.monitoring.alerts.channels' => ['log', 'mail']]);
        Integrity::schedule('04:40');

        config(['foundation.integrity.generated_views' => [self::VIEWS.'/livewire', self::VIEWS.'/*.blade.php']]);
        $this->snapshot();
        $this->assertSame(Status::Ok, (new IntegrityCheck)->run()->status);

        config(['foundation.integrity.generated_views' => ['storage/app/livewire']]);
        $this->snapshot();
        $result = (new IntegrityCheck)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString(Gap::GeneratedViewsRefused->label(), (string) $result->detail);
    }
}
