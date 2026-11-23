<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\DeployCheck\Checks\Integrity as IntegrityCheck;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Integrity\Integrity;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\ViewsMark;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/** 点検 integrity：使っていなければ skip。一覧・schedule・設定・公開ディレクトリ・印・知らせの届き方 */
class IntegrityCheckTest extends TestCase
{
    use IntegrityTree;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log', 'mail']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
        $this->app->instance(ViewsProcess::class, new ViewsProcess(available: true));
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    private function outcome(): array
    {
        $result = (new IntegrityCheck)->run();

        return [$result->status, (string) $result->detail];
    }

    public function test_skips_when_neither_scheduled_nor_snapshotted(): void
    {
        $this->assertSame(Status::Skip, $this->outcome()[0]);
    }

    public function test_ok_with_a_snapshot_and_the_schedule(): void
    {
        $this->snapshot();
        Integrity::schedule('04:40');

        $this->assertSame(Status::Ok, $this->outcome()[0], $this->outcome()[1]);
    }

    public function test_fails_when_scheduled_but_no_snapshot_or_a_broken_one(): void
    {
        Integrity::schedule('04:40');
        $this->assertSame(Status::Fail, $this->outcome()[0]);

        $this->snapshot();
        $lines = file(Snapshot::path());
        array_pop($lines);
        file_put_contents(Snapshot::path(), implode('', $lines));
        $this->assertSame(Status::Fail, $this->outcome()[0]);
    }

    public function test_fails_when_a_snapshot_exists_but_the_schedule_line_is_gone(): void
    {
        $this->snapshot();

        [$status, $detail] = $this->outcome();
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('schedule にない', $detail);
    }

    public function test_fails_when_the_settings_differ_and_says_to_make_the_snapshot_again(): void
    {
        $this->snapshot();
        Integrity::schedule('04:40');
        config(['foundation.integrity.exclude' => ['database']]);

        [$status, $detail] = $this->outcome();
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('一覧を作り直してから流し直す', $detail);
    }

    public function test_fails_when_exclude_served_or_acme_cover_what_the_check_loads(): void
    {
        foreach ([['foundation.integrity.exclude' => ['app']], ['foundation.integrity.served' => ['vendor/x']], ['foundation.integrity.acme' => 'config/app.php'], ['foundation.integrity.exclude' => ['.env']]] as $settings) {
            config(['foundation.integrity.exclude' => [], 'foundation.integrity.served' => [], 'foundation.integrity.acme' => 'public/.well-known/acme-challenge']);
            config($settings);
            $this->snapshot();
            Integrity::schedule('04:40');

            [$status, $detail] = $this->outcome();
            $this->assertSame(Status::Fail, $status, json_encode($settings));
            $this->assertStringContainsString('読み込む場所', $detail);
        }
    }

    public function test_fails_when_the_public_directory_is_outside_the_tree(): void
    {
        $this->snapshot();
        Integrity::schedule('04:40');
        $outside = sys_get_temp_dir().'/foundation-integrity-pub-'.bin2hex(random_bytes(4));
        mkdir($outside);
        $this->app->usePublicPath($outside);

        $this->assertSame(Status::Fail, $this->outcome()[0]);
        rmdir($outside);
    }

    public function test_fails_when_a_crashed_views_mark_is_left(): void
    {
        $this->snapshot();
        Integrity::schedule('04:40');
        file_put_contents(dirname(Snapshot::path()).'/'.ViewsMark::PREFIX.'999999.abcdefabcdef', (time() - 99999).' 999999 abcdefabcdef');

        [$status, $detail] = $this->outcome();
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('画面の調べが落ちた', $detail);
    }

    public function test_later_when_not_running_in_maintenance_or_when_paused_or_alerts_do_not_reach_anyone(): void
    {
        $this->snapshot();
        app(Schedule::class)->command(Integrity::COMMAND)->daily();
        $this->assertSame(Status::Later, $this->outcome()[0]);

        $this->app->forgetInstance(Schedule::class);
        Integrity::schedule('04:40');
        config(['foundation.monitoring.alerts.channels' => ['log']]);
        $this->assertSame(Status::Later, $this->outcome()[0]);

        config(['foundation.monitoring.alerts.channels' => ['log', 'mail'], 'foundation.monitoring.enabled' => false]);
        $this->assertSame(Status::Later, $this->outcome()[0]);

        config(['foundation.monitoring.enabled' => true]);
        $this->app->instance(ViewsProcess::class, new ViewsProcess(available: false));
        $this->assertSame(Status::Later, $this->outcome()[0]);
    }

    public function test_a_pipe_as_the_snapshot_does_not_stop_the_check(): void
    {
        Integrity::schedule('04:40');
        mkdir(dirname(Snapshot::path()), 0775, true);
        posix_mkfifo(Snapshot::path(), 0644);

        $result = $this->inChild(fn () => (new IntegrityCheck)->run()->status->value, 10);

        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertSame('fail', $result['result']['result']);
    }

    public function test_reads_only_the_head_and_the_tail_so_a_big_snapshot_is_quick(): void
    {
        for ($i = 0; $i < 3000; $i++) {
            $this->writeFile('vendor/many/f'.$i.'.php', (string) $i);
        }
        $this->snapshot();
        Integrity::schedule('04:40');

        $started = microtime(true);
        $this->assertSame(Status::Ok, $this->outcome()[0]);
        $this->assertLessThan(1, microtime(true) - $started);
    }
}
