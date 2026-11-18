<?php

namespace Miraqceed\Foundation\Tests\Feature\Failures;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Csv\CsvResponse;
use Miraqceed\Foundation\Health\Health;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\TaskRuns;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/**
 * パッケージの中で失敗を記録して続ける所は、ログが書けなくても投げない（report() が投げても本筋を止めない）
 */
class PackageReportingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
    }

    protected function defineRoutes($router): void
    {
        Health::route();
    }

    /** DB を落とす（つながらない接続を既定にする） */
    private function databaseDown(): void
    {
        config([
            'database.default' => 'down',
            'database.connections.down' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'foundation_test', 'username' => 'x', 'password' => 'x'],
        ]);
        DB::purge();
    }

    /**
     * 記録の失敗の1行（error_log）を、試しの出力に出さない
     *
     * @return array<int, string> 記録しようとした例外の文
     */
    private function withBrokenLog(callable $run): array
    {
        $attempts = ReportFailure::simulate();
        $path = tempnam(sys_get_temp_dir(), 'foundation-error-log-');
        $previous = ini_set('error_log', $path);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($path);
        }

        return $attempts->getArrayCopy();
    }

    public function test_稼働の確かめは_NG_の500を返す(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('DB に届かない'));

        $attempts = $this->withBrokenLog(function () {
            $response = $this->get('/up');

            $response->assertStatus(500);
            $this->assertSame('NG', $response->getContent());
        });

        $this->assertSame(['DB に届かない'], $attempts);
    }

    public function test_定期の処理の前後の記録は投げない(): void
    {
        $this->databaseDown();

        $attempts = $this->withBrokenLog(function () {
            TaskRuns::started('nightly');
            TaskRuns::finished('nightly', true);
        });

        $this->assertCount(2, $attempts);
    }

    public function test_知らせは_DB_が落ちても投げない(): void
    {
        $this->databaseDown();
        $sender = $this->app->make(AlertSender::class);

        $attempts = $this->withBrokenLog(function () use ($sender) {
            $this->assertNull($sender->send('k', '件名', '本文'));
            $this->assertSame(0, $sender->retry());
        });

        $this->assertCount(2, $attempts);
    }

    public function test_CSV_の途中の失敗は終わりの印を残す(): void
    {
        $rows = (function () {
            yield ['a'];
            throw new RuntimeException('読めない');
        })();

        $attempts = $this->withBrokenLog(function () use ($rows) {
            $response = CsvResponse::download('x.csv', ['h'], $rows);

            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();

            $this->assertStringContainsString('a', $content);
            $this->assertStringContainsString(CsvResponse::INCOMPLETE_LINE, $content);
        });

        $this->assertSame(['読めない'], $attempts);
    }

    public function test_本番の_bypass_は数え続けて知らせる(): void
    {
        config(['cache.default' => 'array', 'foundation.attempts.bypass' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $attempts = $this->app->make(Attempts::class);
        $limit = new Limit('t:bypass', 1, 60);

        $reported = $this->withBrokenLog(function () use ($attempts, $limit) {
            $attempts->attempt([$limit], fn () => false);

            try {
                $attempts->attempt([$limit], fn () => true);
                $this->fail('本番では bypass を効かせない');
            } catch (TooManyAttempts) {
            }
        });

        $this->assertCount(1, $reported);
        $this->assertStringContainsString('foundation.attempts.bypass', $reported[0]);
    }
}
