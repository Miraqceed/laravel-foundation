<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Miraqceed\Foundation\Integrity\Checks\MaintenanceCheck;
use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Integrity;
use Miraqceed\Foundation\Integrity\IntegrityChecker;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\ViewsMark;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Monitoring\StallCheck;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/**
 * 調べを束ねる命令：出力・1つの期限・知らせ（部品5の本物の表）・記録の1行・schedule の助け・子を使えないときの区切りの知らせ
 */
class IntegrityCommandTest extends TestCase
{
    use IntegrityTree;
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('foundation.monitoring.external', []);
        $app['config']->set('foundation.monitoring.heartbeat_url', null);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownTree();
        parent::tearDown();
    }

    public function test_the_command_prints_one_line_per_finding_and_ends_with_1_or_0(): void
    {
        $this->snapshot();
        $this->assertSame(0, Artisan::call('foundation:integrity-check'));
        $this->assertStringContainsString('違いはありません', Artisan::output());

        $this->writeFile('public/shell.php', '<?php');
        $this->assertSame(1, Artisan::call('foundation:integrity-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('[added] public/shell.php', $output);
        $this->assertStringContainsString('[public-php] public/shell.php', $output);
    }

    public function test_one_failing_check_does_not_hide_the_others_and_its_message_never_shows(): void
    {
        $this->snapshot();
        $this->writeFile('public/index.php', '<?php // changed');
        $this->app->instance(MaintenanceCheck::class, new class extends MaintenanceCheck
        {
            public function run(Context $context): void
            {
                throw new RuntimeException('opendir(/home/someone/x-absolute-path-x): failed');
            }
        });
        $reported = ReportFailure::simulate();

        $lines = [];
        $report = $this->app->make(IntegrityChecker::class)->run(function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertFinding($report, Finding::Changed, 'public/index.php');
        $this->assertContains('failed:maintenance: RuntimeException', $this->gaps($report));
        $everything = implode("\n", $lines).implode("\n", (array) $reported).implode("\n", array_map(fn ($a) => $a->body, DB::table('foundation_alerts')->get()->all()));
        $this->assertStringNotContainsString('x-absolute-path-x', $everything);
        $this->assertStringNotContainsString($this->root, $everything);
    }

    public function test_one_deadline_lets_the_tree_finish_before_a_huge_storage_and_many_views(): void
    {
        $this->snapshot();
        $this->writeFile('public/index.php', '<?php // changed');
        for ($i = 0; $i < 200; $i++) {
            mkdir($this->root.'/storage/app/d'.$i);
            for ($j = 0; $j < 1000; $j++) {
                touch($this->root.'/storage/app/d'.$i.'/f'.$j);
            }
        }
        for ($i = 0; $i < 400; $i++) {
            file_put_contents($this->root.'/storage/framework/views/'.sprintf('%032d', $i).'.php', 'x');
        }
        config(['foundation.integrity.seconds' => 0.4]);

        $started = microtime(true);
        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, 'public/index.php');
        $this->assertGap($report, Gap::TimedOut);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function test_tells_through_the_alerts_once_per_set_and_again_the_next_day(): void
    {
        $this->snapshot();
        $this->writeFile('public/shell.php', '<?php');
        Carbon::setTestNow('2026-11-20 04:40:00');

        $this->check();
        $this->check();
        $this->assertSame(1, DB::table('foundation_alerts')->count());
        $alert = DB::table('foundation_alerts')->first();
        $this->assertStringStartsWith(IntegrityChecker::ALERT_KEY, $alert->key);
        $this->assertStringContainsString('public/shell.php', $alert->body);

        $this->writeFile('public/other.php', '<?php');
        $this->check();
        $this->assertSame(2, DB::table('foundation_alerts')->count());

        Carbon::setTestNow('2026-11-21 04:40:00');
        $this->check();
        $this->assertSame(3, DB::table('foundation_alerts')->count());
    }

    public function test_the_body_fits_and_counts_and_names_cannot_use_chat_markup(): void
    {
        $this->snapshot();
        for ($i = 0; $i < 100; $i++) {
            $this->writeFile('public/f'.$i.'-'.str_repeat('n', 40).'.php', '<?php');
        }
        $this->writeFile('public/<!channel>.php', '<?php');
        Http::preventStrayRequests();
        Http::fake();
        config(['foundation.monitoring.alerts.channels' => ['log', 'slack'], 'foundation.monitoring.alerts.slack.webhook_url' => 'https://hooks.slack.test/T/B/X']);

        $this->check();

        $alert = DB::table('foundation_alerts')->first();
        $this->assertLessThanOrEqual(4000, mb_strlen($alert->body));
        $this->assertMatchesRegularExpression('/ほか \d+ 件/', $alert->body);
        Http::assertSent(function (Request $request) {
            $text = (string) ($request->data()['text'] ?? '');

            return ! str_contains($text, '<!channel>') && (str_contains($text, '&lt;!channel&gt;') || str_contains($text, 'ほか'));
        });
        $this->assertStringNotContainsString('<!channel>', $alert->body);
    }

    public function test_with_monitoring_disabled_only_the_log_and_output_tell(): void
    {
        config(['foundation.monitoring.enabled' => false]);
        $this->writeFile('public/shell.php', '<?php');
        $reported = ReportFailure::simulate();

        $this->check();

        $this->assertSame(0, DB::table('foundation_alerts')->count());
        $this->assertNotEmpty((array) $reported);
        $this->assertStringContainsString('public/shell.php', implode("\n", (array) $reported));
    }

    public function test_records_one_line_of_counts_only(): void
    {
        $this->snapshot();
        $this->writeFile('public/shell.php', '<?php');
        Log::spy();

        $this->check();

        // 子を使えないとき（この試しの木の既定）は、区切りと終わりの2回
        Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message) {
            return $level === 'warning' && str_starts_with($message, 'foundation.integrity {') && ! str_contains($message, 'shell')
                && str_contains($message, '"differences":2');
        })->twice();
    }

    public function test_a_failing_record_channel_does_not_stop_the_check(): void
    {
        config(['foundation.integrity.record_channel' => 'no-such-channel', 'logging.channels.no-such-channel' => ['driver' => 'custom', 'via' => fn () => throw new RuntimeException('x')]]);
        $this->snapshot();

        $this->assertTrue($this->check()->isClean());
    }

    public function test_the_schedule_helper_registers_daily_even_in_maintenance_and_when_paused(): void
    {
        $event = Integrity::schedule('04:40');

        $this->assertSame('40 4 * * *', $event->expression);
        $this->assertTrue($event->runsInMaintenanceMode());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        if (method_exists($event, 'evenWhenPaused')) {
            $this->assertTrue($event->runsWhenPaused());
        }
        $this->assertSame($event, Integrity::scheduledEvent());
        $this->assertArrayHasKey(Integrity::WATCH_NAME, app(\Miraqceed\Foundation\Monitoring\WatchList::class)->all());
    }

    public function test_the_schedule_helper_refuses_a_deadline_longer_than_the_lock(): void
    {
        config(['foundation.integrity.seconds' => 600]);

        $this->expectException(LogicException::class);
        Integrity::schedule('04:40');
    }

    /**
     * 違いが残る（1 で終わる）間は、26時間後から見張りが「止まっています」を出し、心拍を送らない。
     * schedule の命令は子のプロセス（testbench の雛形のアプリ）で流れ、この試しの木を見ないので、ここでは 1 で終わる回を流す。
     * 直した後に schedule:test で止まることは、使い捨てのアプリで確かめる（設計書 10.1 の12行目）
     */
    public function test_while_the_check_keeps_failing_the_watch_says_stalled_after_26_hours(): void
    {
        Integrity::schedule('04:40');
        Http::preventStrayRequests();
        Http::fake();
        config(['foundation.monitoring.heartbeat_url' => 'https://heartbeat.example.com/x']);
        Carbon::setTestNow('2026-11-20 04:40:00');
        $this->assertSame(0, Artisan::call('foundation:watch'));
        Artisan::call('schedule:test', ['--name' => 'foundation:integrity-check']);

        Carbon::setTestNow('2026-11-21 07:00:00');
        $beats = count(Http::recorded());
        $this->assertSame(1, Artisan::call('foundation:watch'));
        $this->assertSame(['integrity-check'], array_map(fn ($task) => $task->name, app(StallCheck::class)->stalled()));
        $this->assertSame($beats, count(Http::recorded()), '止まっている間は心拍を送らない');
    }

    public function test_without_a_child_the_views_run_in_process_with_a_break_first(): void
    {
        $this->snapshot();
        $this->writeFile('public/shell.php', '<?php');
        file_put_contents($this->root.'/storage/framework/views/'.str_repeat('a', 32).'.php', 'x');
        Log::spy();

        $report = $this->check();

        $this->assertSame('in-process', $report->viewsMode);
        $this->assertFinding($report, Finding::ViewWithoutSource, 'storage/framework/views/'.str_repeat('a', 32).'.php');
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message) => str_contains($message, '"phase":"before-views"'))->once();
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message) => str_contains($message, '"phase":"end"'))->once();
        $this->assertSame(2, DB::table('foundation_alerts')->count());
        $this->assertStringContainsString('子プロセスなしで流しました', (string) DB::table('foundation_alerts')->first()->body);
        $this->assertSame([], glob(dirname(Snapshot::path()).'/'.ViewsMark::PREFIX.'*'));
    }

    /** 別のプロセスで流す（ほかの試しで太った親から fork すると、128M の子が初めから網を超える） */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_when_the_in_process_views_crash_the_findings_before_them_arrive_and_the_next_run_tells(): void
    {
        $this->snapshot();
        $this->writeFile('public/shell.php', '<?php');
        // 印はないが、compileString() が元の約50倍のメモリーを使う形（見積もりを外すと 128M で落ちる）
        $dense = $this->writeFile('resources/views/dense.blade.php', str_repeat("<?php ;?>\n", 200_000));
        $compiler = app('blade.compiler');
        file_put_contents($this->root.'/storage/framework/views/'.basename($compiler->getCompiledPath($dense)), "x<?php /**PATH {$dense} ENDPATH**/ ?>");

        // 落ちる子の DB（:memory:）は親から見えないので、知らせと記録はファイルのログで読む
        $log = $this->root.'/storage/logs/told.log';
        config([
            'logging.channels.told' => ['driver' => 'single', 'path' => $log, 'level' => 'debug'],
            'foundation.monitoring.alerts.log.channel' => 'told',
            'foundation.integrity.record_channel' => 'told',
        ]);

        $result = $this->inChild(function () {
            Limits::overrideForTests(['view_estimate' => 0]);
            $this->check();

            return 'not crashed';
        }, 120, '128M');

        $this->assertNotSame(0, $result['exit'], 'the child should have run out of memory: '.json_encode($result));
        $told = (string) @file_get_contents($log);
        $this->assertStringContainsString('foundation.alert ', $told, 'the alert before the views must have been sent');
        $this->assertStringContainsString('public/shell.php', $told);
        $this->assertStringContainsString('"phase":"before-views"', $told);
        $this->assertStringNotContainsString('"phase":"end"', $told);
        $this->assertNotEmpty(glob(dirname(Snapshot::path()).'/'.ViewsMark::PREFIX.'*'));

        unlink($dense);
        $report = $this->check();
        $this->assertGap($report, Gap::PreviousViewsCrashed);
        $this->assertSame([], glob(dirname(Snapshot::path()).'/'.ViewsMark::PREFIX.'*'));
    }

    public function test_another_runs_mark_is_not_removed_and_an_unwritable_mark_is_told(): void
    {
        $this->snapshot();
        $directory = dirname(Snapshot::path());
        $live = $directory.'/'.ViewsMark::PREFIX.getmypid().'.abcdefabcdef';
        file_put_contents($live, time().' '.getmypid().' abcdefabcdef');
        file_put_contents($this->root.'/storage/framework/views/'.str_repeat('b', 32).'.php', 'x');

        $report = $this->check();
        $this->assertFileExists($live);
        $this->assertFalse($report->hasGap(Gap::PreviousViewsCrashed));
        unlink($live);

        $this->requireNotRoot();
        chmod($directory, 0555);
        $report = $this->check();
        chmod($directory, 0775);
        $this->assertGap($report, Gap::MarkUnwritable);
    }

    public function test_the_child_is_used_when_available_and_no_mark_or_break_is_made(): void
    {
        $this->app->instance(ViewsProcess::class, new ViewsProcess(ViewsProcess::testCommand(dirname(__DIR__, 2).'/Support/Integrity/views-child.php')));
        $this->snapshot();
        $this->writeFile('public/shell.php', '<?php');
        file_put_contents($this->root.'/storage/framework/views/'.str_repeat('c', 32).'.php', 'x');
        Log::spy();

        $report = $this->check();

        $this->assertSame('child', $report->viewsMode);
        $this->assertFinding($report, Finding::ViewWithoutSource, 'storage/framework/views/'.str_repeat('c', 32).'.php');
        $this->assertSame(1, DB::table('foundation_alerts')->count());
        Log::shouldNotHaveReceived('log', fn ($level, $message) => str_contains($message, 'phase'));
    }
}
