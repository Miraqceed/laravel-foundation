<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）で、同じ合言葉を同じ時刻に受け取る。通るのはちょうど1つ。
 * PostgreSQL・MySQL で流す（SQLite は書き込みを1つずつ行うので、並べても同時にならない）。
 * 合言葉は子から見えるよう、トランザクションの外で書いてコミットする（表は試しが作って消す）
 */
class ParallelClaimTest extends TestCase
{
    private const PROCESSES = 20;

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

        Schema::dropIfExists('foundation_one_time_codes');
        $migration = require __DIR__.'/../../../database/one-time-code-migrations/2026_11_18_000000_create_foundation_one_time_codes_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::dropIfExists('foundation_one_time_codes');
        }

        parent::tearDown();
    }

    public function test_only_one_process_claims_the_code(): void
    {
        $kind = new CodeKind('token-claim', 'apc_');

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $issued = $this->app->make(OneTimeCodes::class)->issue($kind);

            $outcome = $this->race($kind, $issued->code);

            $this->assertSame([], $outcome['errors'], "round {$round}");
            $this->assertSame(1, $outcome['claimed'], "round {$round}: ".json_encode($outcome));
            $this->assertSame(self::PROCESSES - 1, $outcome['refused'], "round {$round}");
        }
    }

    /** @return array{claimed: int, refused: int, errors: list<string>} */
    private function race(CodeKind $kind, string $code): array
    {
        $log = tempnam(sys_get_temp_dir(), 'claim-race');
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
                    $claimed = $this->app->make(OneTimeCodes::class)->claim($kind, $code, function (): void {
                        // 受け取った後の処理の間、ほかの要求が並ぶ間をつくる
                        usleep(100_000);
                    });
                    file_put_contents($log, ($claimed === null ? 'r' : 'c')."\n", FILE_APPEND | LOCK_EX);
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
            'claimed' => count(array_filter($lines, fn ($l) => $l === 'c')),
            'refused' => count(array_filter($lines, fn ($l) => $l === 'r')),
            'errors' => array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))),
        ];
    }
}
