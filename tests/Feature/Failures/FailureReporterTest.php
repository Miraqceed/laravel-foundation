<?php

namespace Miraqceed\Foundation\Tests\Feature\Failures;

use DomainException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FailureReporterTest extends TestCase
{
    /**
     * $run の間だけ error_log() の行き先を一時のファイルにし、書かれた中身を返す。
     * 試しの本体の中で替える（PHPUnit は試しの本体の間、自分の一時のファイルに替えている）
     */
    private function errorLogDuring(callable $run): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foundation-error-log-');
        $previous = ini_set('error_log', $path);

        try {
            $run();

            return (string) file_get_contents($path);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($path);
        }
    }

    private function reporter(): FailureReporter
    {
        return $this->app->make(FailureReporter::class);
    }

    public function test_記録する(): void
    {
        Exceptions::fake();

        $this->reporter()->report(new RuntimeException('落ちた'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '落ちた');
    }

    public function test_report_が投げても投げない(): void
    {
        $attempts = ReportFailure::simulate();

        $log = $this->errorLogDuring(fn () => $this->reporter()->report(new RuntimeException('落ちた')));

        $this->assertSame(['落ちた'], $attempts->getArrayCopy());
        $this->assertStringContainsString('report() failed for RuntimeException', $log);
    }

    public function test_ログのファイルを開けなくても投げない(): void
    {
        // 親がファイルなので、ログのディレクトリを作れない（Monolog の StreamHandler が投げる）
        $parent = tempnam(sys_get_temp_dir(), 'foundation-not-a-dir-');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $parent.'/laravel.log']);
        $this->app['log']->forgetChannel('single');

        try {
            $log = $this->errorLogDuring(fn () => $this->reporter()->report(new RuntimeException('落ちた')));
        } finally {
            @unlink($parent);
        }

        $this->assertStringContainsString('report() failed for RuntimeException (UnexpectedValueException)', $log);
    }

    public function test_ロガーを作れなくても投げない(): void
    {
        // ロガーを作れないと、Laravel は元の例外を投げ直す
        $this->app->bind(LoggerInterface::class, fn () => throw new RuntimeException('ロガーがない'));

        $log = $this->errorLogDuring(fn () => $this->reporter()->report(new DomainException('落ちた')));

        $this->assertStringContainsString('report() failed for DomainException (DomainException)', $log);
    }

    public function test_記録の失敗は_error_log_に1行で_文を含まない(): void
    {
        ReportFailure::simulate();

        $log = $this->errorLogDuring(fn () => $this->reporter()->report(new RuntimeException('接続元 203.0.113.5 で落ちた')));

        $lines = array_values(array_filter(explode("\n", $log)));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('foundation: report() failed for RuntimeException (UnexpectedValueException)', $lines[0]);
        $this->assertStringNotContainsString('203.0.113.5', $lines[0]);
        $this->assertStringNotContainsString('落ちた', $lines[0]);
    }

    public function test_例外を書き換えない(): void
    {
        $seen = [];
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (DomainException $e) use (&$seen) {
            $seen[] = $e;

            return false;
        });
        $handler->dontReport(InvalidArgumentException::class);
        $original = new DomainException('203.0.113.5 から');

        $log = $this->errorLogDuring(function () use ($original) {
            $this->reporter()->report($original);
            $this->reporter()->report(new InvalidArgumentException('記録しない'));
        });

        $this->assertSame([$original], $seen);
        $this->assertSame('', $log);
    }

    public function test_同じ鍵は窓の中で1回_窓を過ぎればまた記録する(): void
    {
        Exceptions::fake();

        $this->reporter()->report(new RuntimeException('1'), 'k', 60);
        $this->reporter()->report(new RuntimeException('2'), 'k', 60);
        $this->travel(59)->seconds();
        $this->reporter()->report(new RuntimeException('3'), 'k', 60);
        $this->travel(1)->seconds();
        $this->reporter()->report(new RuntimeException('4'), 'k', 60);

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '1');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === '4');
    }

    public function test_鍵が違えば別に数え_鍵なしは間引かない(): void
    {
        Exceptions::fake();

        $this->reporter()->report(new RuntimeException('a'), 'a');
        $this->reporter()->report(new RuntimeException('b'), 'b');
        $this->reporter()->report(new RuntimeException('c'));
        $this->reporter()->report(new RuntimeException('d'));

        Exceptions::assertReportedCount(4);
    }

    public function test_目印を書けないときは毎回記録する(): void
    {
        Exceptions::fake();
        $parent = tempnam(sys_get_temp_dir(), 'foundation-not-a-dir-');
        config(['foundation.failures.throttle_path' => $parent.'/throttle']);

        try {
            $this->reporter()->report(new RuntimeException('1'), 'k');
            $this->reporter()->report(new RuntimeException('2'), 'k');
        } finally {
            @unlink($parent);
        }

        Exceptions::assertReportedCount(2);
    }

    public function test_目印のファイルの名前に鍵が出ない(): void
    {
        Exceptions::fake();

        $this->reporter()->report(new RuntimeException('1'), 'click:203.0.113.5');

        $names = scandir((string) config('foundation.failures.throttle_path'));
        $this->assertSame([hash('sha256', 'click:203.0.113.5')], array_values(array_diff($names, ['.', '..'])));
    }

    public function test_間引きは_DB_とキャッシュに触れない(): void
    {
        Exceptions::fake();
        // 本番の形：キャッシュは database。DB は落ちている
        config([
            'cache.default' => 'database',
            'database.default' => 'down',
            'database.connections.down' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'foundation_test', 'username' => 'x', 'password' => 'x'],
        ]);
        DB::purge();
        $this->app['cache']->forgetDriver('database');

        $this->reporter()->report(new RuntimeException('1'), 'k');
        $this->reporter()->report(new RuntimeException('2'), 'k');

        Exceptions::assertReportedCount(1);
        $this->assertSame([], DB::getConnections());
    }
}
