<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Database\LockNotAcquired;
use Miraqceed\Foundation\Database\NamedLock;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）を同じ時刻に始めさせ、同じ名前のロックの中で「数えて → 足す」をする。
 * ロックの中にいるのはいつも1つだけで、上限より多く足さない。PostgreSQL・MySQL で流す（SQLite はロックを取らない）
 */
class NamedLockProcessesTest extends TestCase
{
    private const MAX = 3;

    private const PROCESSES = 20;

    private const ROUNDS = 3;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('PostgreSQL・MySQL で流す（SQLite ではロックを取らない）');
        }

        Schema::dropIfExists('named_lock_counts');
        Schema::create('named_lock_counts', function ($table) {
            $table->id();
            $table->integer('round');
        });
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('named_lock_counts');
        }

        parent::tearDown();
    }

    public function test_only_one_process_at_a_time_holds_the_name_and_the_count_stays_within_the_limit(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $outcome = $this->race($round);

            $this->assertSame([], $outcome['errors'], "round {$round}");
            $this->assertSame(0, $outcome['refused'], "round {$round}: 待つ秒の内に、どのプロセスも取れる");
            $this->assertSame(self::PROCESSES, count($outcome['spans']), "round {$round}");
            $this->assertSame(self::MAX, DB::table('named_lock_counts')->where('round', $round)->count(), "round {$round}");

            // ロックの中にいた時間が重ならない
            $spans = $outcome['spans'];
            usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($spans); $i++) {
                $this->assertGreaterThanOrEqual($spans[$i - 1][1], $spans[$i][0], "round {$round}: 2つのプロセスが同時にロックの中にいた");
            }
        }
    }

    /** @return array{spans: list<array{0: float, 1: float}>, refused: int, errors: list<string>} */
    private function race(int $round): array
    {
        $log = tempnam(sys_get_temp_dir(), 'named-lock-race');
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
                    [$entered, $left] = $this->app->make(NamedLock::class)->run("count:{$round}", 30, function () use ($round): array {
                        $entered = microtime(true);
                        $count = DB::table('named_lock_counts')->where('round', $round)->count();
                        // 数えてから足すまでの間に、ほかのプロセスが並ぶ間をつくる
                        usleep(20_000);
                        if ($count < self::MAX) {
                            DB::table('named_lock_counts')->insert(['round' => $round]);
                        }

                        return [$entered, microtime(true)];
                    });
                    file_put_contents($log, sprintf("s %.6f %.6f\n", $entered, $left), FILE_APPEND | LOCK_EX);
                } catch (LockNotAcquired) {
                    file_put_contents($log, "r\n", FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {
                    file_put_contents($log, 'e '.get_class($e).': '.str_replace("\n", ' ', mb_substr($e->getMessage(), 0, 160))."\n", FILE_APPEND | LOCK_EX);
                }
                // 子の後始末（PHPUnit・接続の片付け）を走らせずに終える。接続が切れればサーバーがロックを放す
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        @unlink($log);

        $spans = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 's ')) {
                [, $entered, $left] = explode(' ', $line);
                $spans[] = [(float) $entered, (float) $left];
            }
        }

        return [
            'spans' => $spans,
            'refused' => count(array_filter($lines, fn (string $l): bool => $l === 'r')),
            'errors' => array_values(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'e '))),
        ];
    }
}
