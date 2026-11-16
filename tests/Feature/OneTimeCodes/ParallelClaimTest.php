<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）。PostgreSQL・MySQL で流す（SQLite は書き込みを1つずつ行うので、並べても同時にならない）。
 * 合言葉は子から見えるよう、トランザクションの外で書いてコミットする（表は試しが作って消す）
 */
class ParallelClaimTest extends TestCase
{
    private const PROCESSES = 20;

    private const ROUNDS = 5;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }
        $this->connection = (string) config('database.default');

        Schema::dropIfExists('foundation_one_time_codes');
        $migration = require __DIR__.'/../../../database/one-time-code-migrations/2026_11_18_000000_create_foundation_one_time_codes_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->useIsolationLevel(null);
            Schema::dropIfExists('foundation_one_time_codes');
        }

        parent::tearDown();
    }

    private function useIsolationLevel(?string $level): void
    {
        config(["database.connections.{$this->connection}.isolation_level" => $level]);
        DB::purge($this->connection);
    }

    public function test_only_one_process_claims_the_code(): void
    {
        $kind = new CodeKind('token-claim', 'apc_');

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $this->assertOnlyOneClaims($kind, "round {$round}");
        }
    }

    /**
     * 分離の水準を上げても、競り負けた側は例外でなく null（PostgreSQL の REPEATABLE READ・SERIALIZABLE は、
     * 同じ行を先に書かれると 40001 で失敗する。受け取りのトランザクションが試し直すと、条件つきの UPDATE が 0 行になる）
     */
    public function test_losers_get_null_under_stricter_isolation_levels(): void
    {
        $kind = new CodeKind('token-claim', 'apc_');

        foreach (['repeatable read', 'serializable'] as $level) {
            $this->useIsolationLevel($level);
            for ($round = 1; $round <= 3; $round++) {
                $this->assertOnlyOneClaims($kind, "{$level} round {$round}");
            }
        }
    }

    /**
     * 別々の対象に同時に出しても、失敗しない。
     * MySQL では、失効の UPDATE が合う行の無い所にも (kind, subject) の索引の隙間のロックを取り、
     * 互いの INSERT がそれを待って行き詰まる（1213 Deadlock）
     */
    public function test_concurrent_issues_for_distinct_subjects_do_not_fail(): void
    {
        $kind = new CodeKind('token-claim', 'apc_');
        $failures = [];

        // 回ごとに4つのプロセスを並べ、それぞれ別の対象に1回だけ出す（対象は索引の同じ隙間に入る）
        for ($round = 0; $round < 30; $round++) {
            $lines = $this->fork(4, function (int $process) use ($kind, $round): string {
                $this->app->make(OneTimeCodes::class)->issue($kind, sprintf('user:%02d-%d', $round, $process));

                return 'ok';
            }, 0.3);
            $failures = [...$failures, ...array_filter($lines, fn ($l) => $l !== 'ok')];
            $this->assertCount(4, $lines, "round {$round}");
        }

        $this->assertSame([], array_values($failures));
        $this->assertSame(30 * 4, DB::table('foundation_one_time_codes')->whereNull('revoked_at')->count());
    }

    private function assertOnlyOneClaims(CodeKind $kind, string $label): void
    {
        $code = $this->app->make(OneTimeCodes::class)->issue($kind)->code;

        $lines = $this->fork(self::PROCESSES, function () use ($kind, $code): string {
            $claimed = $this->app->make(OneTimeCodes::class)->claim($kind, $code, function (): void {
                // 受け取った後の処理の間、ほかの要求が並ぶ間をつくる
                usleep(100_000);
            });

            return $claimed === null ? 'r' : 'c';
        });

        $errors = array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e ')));
        $this->assertSame([], $errors, $label);
        $this->assertSame(1, count(array_keys($lines, 'c', true)), "{$label}: ".json_encode($lines));
        $this->assertSame(self::PROCESSES - 1, count(array_keys($lines, 'r', true)), $label);
    }

    /**
     * 別々のプロセスを同じ時刻に始めさせ、それぞれの結果を1行で返させる（例外は "e 種類: 文"）
     *
     * @param  \Closure(int): string  $work
     * @param  float  $startAfter  そろって始める時刻まで（秒）
     * @return list<string>
     */
    private function fork(int $processes, \Closure $work, float $startAfter = 1.0): array
    {
        $log = tempnam(sys_get_temp_dir(), 'otc-race');
        // 子は親の接続を受け継がず、それぞれつなぎ直す
        DB::disconnect();
        $start = microtime(true) + $startAfter;
        $pids = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork できなかった');
            }
            if ($pid === 0) {
                while (microtime(true) < $start) {
                    usleep(200);
                }
                try {
                    $line = $work($i);
                } catch (\Throwable $e) {
                    $line = 'e '.get_class($e).': '.str_replace("\n", ' ', mb_substr($e->getMessage(), 0, 160));
                }
                file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
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

        return $lines;
    }
}
