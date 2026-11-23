<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）を、鍵の無い所から並べる。照合するのは、ちょうど上限の回数でなければならない。
 *
 * 数えの足し算が原子的でないと（足した分を put で上書きするなど）、窓の始まりに並んだ要求の1回分が消え、上限より多く照合する。
 * 起きるかはプロセスの並びによるので、何回かに分けて流す。PostgreSQL・MySQL の database のキャッシュで流す（SQLite は1つのファイルを
 * 書き込みのロックで順に書くので、並べても同時にならない）
 */
class ParallelProcessesTest extends TestCase
{
    private const MAX = 3;

    private const PROCESSES = 60;

    private const ROUNDS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }

        Schema::dropIfExists('cache');
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('cache');
        }
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        parent::tearDown();
    }

    public function test_windowed_limit_verifies_exactly_the_limit_when_processes_start_together(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $limit = new Limit("t:parallel:windowed:{$round}", self::MAX, 600);

            $outcome = $this->race(fn (Attempts $attempts, \Closure $verify) => $attempts->attempt([$limit], $verify));

            $this->assertSame([], $outcome['errors'], "round {$round}");
            $this->assertSame(self::MAX, $outcome['verified'], "round {$round}: ".json_encode($outcome));
        }
    }

    public function test_consecutive_limit_verifies_exactly_the_limit_when_processes_start_together(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $limit = new ConsecutiveLimit("t:parallel:consecutive:{$round}", self::MAX, 600);

            $outcome = $this->race(fn (Attempts $attempts, \Closure $verify) => $attempts->attemptConsecutive($limit, $verify));

            $this->assertSame([], $outcome['errors'], "round {$round}");
            $this->assertSame(self::MAX, $outcome['verified'], "round {$round}: ".json_encode($outcome));
        }
    }

    /**
     * 別々のプロセスを同じ時刻に始めさせ、それぞれが1回試みる。照合の中で少し待ち、ほかの要求が並ぶ間をつくる
     *
     * @param  \Closure(Attempts, \Closure():bool): bool  $attempt
     * @return array{verified: int, refused: int, errors: list<string>}
     */
    private function race(\Closure $attempt): array
    {
        $log = tempnam(sys_get_temp_dir(), 'attempts-race');
        // 前の試しのアプリが循環の参照で残り、ごみ集めまで DB の接続を持ったままになることがある。
        // 60 のプロセスが同時につなぐので、先に集めて、接続の上限（PostgreSQL の既定は 100）に当たらないようにする
        gc_collect_cycles();
        // 子は親の接続を受け継がず、それぞれつなぎ直す
        DB::disconnect();
        $start = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < self::PROCESSES; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork できなかった');
            }
            if ($pid === 0) {
                while (microtime(true) < $start) {
                    usleep(200);
                }
                try {
                    $attempt($this->app->make(Attempts::class), function () use ($log): bool {
                        file_put_contents($log, "v\n", FILE_APPEND | LOCK_EX);
                        usleep(200_000);

                        return false;
                    });
                } catch (TooManyAttempts) {
                    file_put_contents($log, "r\n", FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {
                    file_put_contents($log, 'e '.get_class($e).': '.str_replace("\n", ' ', mb_substr($e->getMessage(), 0, 160))."\n", FILE_APPEND | LOCK_EX);
                }
                // 子の後始末（PHPUnit・接続の片付け）を走らせずに終える
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        @unlink($log);

        return [
            'verified' => count(array_filter($lines, fn ($l) => $l === 'v')),
            'refused' => count(array_filter($lines, fn ($l) => $l === 'r')),
            'errors' => array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))),
        ];
    }
}
