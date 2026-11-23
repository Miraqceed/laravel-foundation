<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\DeployCheck\Checks\Integrity as IntegrityCheck;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\TestCase;

/** 入れただけでは変わらない：schedule・storage・点検・起動・既定の設定 */
class IntegrityInstalledOnlyTest extends TestCase
{
    public function test_nothing_is_scheduled_nothing_is_written_and_the_check_skips(): void
    {
        $this->assertSame([], array_values(array_filter(app(Schedule::class)->events(), fn ($e) => str_contains((string) $e->command, 'integrity'))));
        $this->assertDirectoryDoesNotExist(storage_path('app/foundation/integrity'));
        $this->assertSame(Status::Skip, (new IntegrityCheck)->run()->status);
    }

    public function test_the_commands_are_registered_and_the_child_command_is_hidden(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('foundation:integrity-check', $commands);
        $this->assertArrayHasKey('foundation:integrity-snapshot', $commands);
        $this->assertArrayHasKey('foundation:integrity-views', $commands);
        $this->assertTrue($commands['foundation:integrity-views']->isHidden());
    }

    public function test_booting_the_provider_does_not_read_the_integrity_settings(): void
    {
        config(['foundation.integrity' => new class
        {
            public function __get(string $name): never
            {
                throw new \LogicException('read while booting');
            }
        }]);

        $this->app->register(FoundationServiceProvider::class, force: true);

        $this->assertTrue(true);
    }

    public function test_the_default_settings_have_no_env_call_and_ignore_environment_variables(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/config/foundation.php');
        $start = strpos($source, "'integrity' => [");
        $end = strpos($source, "\n    ],", (int) $start);
        $section = substr($source, (int) $start, (int) $end - (int) $start);

        $this->assertNotSame('', $section);
        $this->assertStringNotContainsString('env(', $section);

        putenv('FOUNDATION_INTEGRITY_SNAPSHOT=/tmp/x');
        $_ENV['FOUNDATION_INTEGRITY_SNAPSHOT'] = '/tmp/x';
        $config = require dirname(__DIR__, 3).'/config/foundation.php';
        putenv('FOUNDATION_INTEGRITY_SNAPSHOT');
        unset($_ENV['FOUNDATION_INTEGRITY_SNAPSHOT']);

        $this->assertNull($config['integrity']['snapshot']);
        $this->assertSame([], $config['integrity']['exclude']);
    }
}
