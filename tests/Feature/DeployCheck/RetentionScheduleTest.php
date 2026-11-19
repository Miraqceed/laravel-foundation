<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\DeployCheck\Checks\RetentionSchedule;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Notices\Notice;
use Miraqceed\Foundation\Tests\Support\Retention\Click;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * retention_schedule：台帳がなければ skip、期限の消し方が本番の schedule になければ FAIL、そろえば ok
 */
class RetentionScheduleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.retention.map', ShopDataMap::class);
        $app->detectEnvironment(fn () => 'production');
    }

    private function scheduleAll(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('model:prune', ['--model' => [Click::class]])->daily();
        $schedule->command('queue:prune-failed')->daily();
    }

    public function test_台帳がなければ_skip(): void
    {
        config(['foundation.retention.map' => null]);

        $this->assertSame(Status::Skip, (new RetentionSchedule)->run()->status);
    }

    public function test_読めない台帳は_FAIL(): void
    {
        config(['foundation.retention.map' => Click::class]);

        $result = (new RetentionSchedule)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('LogicException', (string) $result->detail);
    }

    public function test_schedule_になければ_FAIL_足す1行が出る(): void
    {
        $result = (new RetentionSchedule)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString(Click::class, (string) $result->detail);
        $this->assertStringContainsString("Schedule::command('queue:prune-failed')->daily();", (string) $result->detail);
    }

    public function test_そろえば_ok(): void
    {
        $this->scheduleAll();

        $this->assertSame(Status::Ok, (new RetentionSchedule)->run()->status);
    }

    public function test_ほかの環境だけで動く処理は数えない(): void
    {
        $this->scheduleAll();
        $this->app->make(Schedule::class)->command('model:prune', ['--model' => [Notice::class]])->daily()->environments('staging');
        config(['foundation.notices.enabled' => true]);

        $result = (new RetentionSchedule)->run();

        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString(Notice::class, (string) $result->detail);
    }

    public function test_パッケージの表は_部品が有効なときだけ数え_DB_を見ない(): void
    {
        $this->scheduleAll();
        $this->assertSame(Status::Ok, (new RetentionSchedule)->run()->status);

        config(['foundation.notices.enabled' => true]);
        $this->assertSame(Status::Fail, (new RetentionSchedule)->run()->status);

        $this->app->make(Schedule::class)->command('model:prune', ['--model' => [Notice::class]])->daily();
        $this->assertSame(Status::Ok, (new RetentionSchedule)->run()->status);
    }
}
