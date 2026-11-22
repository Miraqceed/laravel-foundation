<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）。PostgreSQL・MySQL で流す（SQLite は書き込みを1つずつ行うので、並べても同時にならない）。
 * 鍵は子から見えるよう、トランザクションの外で書いてコミットする（表は試しが作って消す）
 */
class ParallelRotationTest extends TestCase
{
    private const PROCESSES = 20;

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

        Schema::dropIfExists(MachineTokens::TABLE);
        $migration = require __DIR__.'/../../../database/machine-token-migrations/2026_11_22_000000_create_foundation_machine_tokens_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->useIsolationLevel(null);
            Schema::dropIfExists(MachineTokens::TABLE);
        }

        parent::tearDown();
    }

    /** 表を作って消したので、次のクラスが今の DB をそのまま使わないように移行の状態を戻す */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    private static function kind(): MachineKind
    {
        return new MachineKind('server-agent', 'sag_', 3600);
    }

    private function tokens(): MachineTokens
    {
        return $this->app->make(MachineTokens::class);
    }

    private function useIsolationLevel(?string $level): void
    {
        config(["database.connections.{$this->connection}.isolation_level" => $level]);
        DB::purge($this->connection);
    }

    /** @return array{string, string} [今の鍵, 入れ替え待ちの鍵] */
    private function rotated(string $subject): array
    {
        $current = $this->tokens()->issue(self::kind(), $subject)->token;
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertNotNull($pending);

        return [$current, $pending];
    }

    public function test_a_pending_token_used_by_many_processes_at_once_switches_once(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $this->assertSwitchesOnce("round {$round}");
        }
    }

    public function test_current_and_pending_tokens_used_at_once_are_all_accepted(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            [$current, $pending] = $this->rotated("server:{$round}");

            $lines = $this->fork(self::PROCESSES, function (int $process) use ($current, $pending): string {
                $machine = $this->tokens()->authenticate(self::kind(), $process % 2 === 0 ? $current : $pending);

                return $machine === null ? 'refused' : 'ok';
            });

            $this->assertSame(array_fill(0, self::PROCESSES, 'ok'), $lines, "round {$round}: ".json_encode($lines));
            $row = $this->row("server:{$round}");
            $this->assertSame(CodeFormat::hash($pending), $row->current_hash);
            $this->assertSame(CodeFormat::hash($current), $row->previous_hash);
        }
    }

    /** 入れ替えと、入れ替え待ちの初めての使用が同時に来ても、機械が持つ鍵のどれかは効く（締め出されない） */
    public function test_rotations_racing_a_switch_never_lock_the_machine_out(): void
    {
        for ($round = 1; $round <= 10; $round++) {
            $subject = "server:{$round}";
            [$current, $pending] = $this->rotated($subject);

            $lines = $this->fork(6, function (int $process) use ($current, $pending): string {
                if ($process % 2 === 0) {
                    $new = $this->tokens()->rotate(self::kind(), $current);

                    return $new === null ? 'rotate-refused' : 'rotated '.$new->token;
                }

                return $this->tokens()->authenticate(self::kind(), $pending) === null ? 'auth-refused' : 'auth-ok';
            }, 0.5);

            $this->assertCount(6, $lines, "round {$round}");
            $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))), "round {$round}");
            $returned = array_map(fn ($l) => CodeFormat::hash(substr($l, 8)), array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'rotated '))));

            $row = $this->row($subject);
            $hashes = array_filter([$row->current_hash, $row->pending_hash, $row->previous_hash]);
            $this->assertSame(count($hashes), count(array_unique($hashes)), "round {$round}: 同じ鍵が2つの枠にある");
            // 今の鍵は元の今か元の入れ替え待ち、前の鍵は元の今だけ、入れ替え待ちは返した鍵だけ
            $this->assertContains($row->current_hash, [CodeFormat::hash($current), CodeFormat::hash($pending)], "round {$round}");
            $this->assertContains($row->previous_hash, [null, CodeFormat::hash($current)], "round {$round}");
            if ($row->pending_hash !== null) {
                $this->assertContains($row->pending_hash, $returned, "round {$round}");
            }
            // 元の今の鍵か元の入れ替え待ちの鍵のどちらかは、いま効く
            $this->assertTrue(
                $this->tokens()->authenticate(self::kind(), $current) !== null || $this->tokens()->authenticate(self::kind(), $pending) !== null,
                "round {$round}: 締め出された ".json_encode($lines),
            );
        }
    }

    /**
     * 促しに従う機械の並んだ要求が、続けざまに入れ替えて互いの入れ替え待ちを消し合わない（設計書 Q9）。
     * 期限の内の入れ替え待ちがあれば促さないので、入れ替えるのは最初にそろって読んだ要求だけ
     */
    public function test_processes_following_the_rotate_hint_do_not_keep_replacing_each_others_pending_token(): void
    {
        $this->travelTo(CarbonImmutable::now('UTC')->subHours(2));
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $this->travelBack();
        $processes = 10;
        $requests = 5;

        $lines = $this->fork($processes, function () use ($current, $requests): string {
            $rotations = [];
            for ($request = 0; $request < $requests; $request++) {
                $machine = $this->tokens()->authenticate(self::kind(), $current);
                if ($machine === null) {
                    return 'refused';
                }
                if ($machine->shouldRotate) {
                    $rotations[] = $request;
                    $this->tokens()->rotate(self::kind(), $current);
                }
                usleep(50_000);
            }

            return 'rotations '.implode(',', $rotations);
        });

        $this->assertCount($processes, $lines);
        $this->assertSame([], array_values(array_filter($lines, fn ($l) => ! str_starts_with($l, 'rotations '))), json_encode($lines));
        $later = array_filter($lines, fn ($l) => preg_match('/[1-9]/', substr($l, 10)) === 1);
        $this->assertSame([], array_values($later), '2回目より後の要求で入れ替えた: '.json_encode($lines));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $current));
    }

    public function test_revoke_racing_switches_and_rotations_leaves_nothing_working(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $subject = "server:{$round}";
            [$current, $pending] = $this->rotated($subject);

            $lines = $this->fork(9, function (int $process) use ($current, $pending, $subject): string {
                return match ($process % 3) {
                    0 => $this->tokens()->revoke(self::kind(), $subject) ? 'revoked' : 'revoke-none',
                    1 => $this->tokens()->authenticate(self::kind(), $pending) === null ? 'auth-refused' : 'auth-ok',
                    default => $this->tokens()->rotate(self::kind(), $current) === null ? 'rotate-refused' : 'rotated',
                };
            }, 0.5);

            $this->assertCount(9, $lines, "round {$round}");
            $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))), "round {$round}");
            $this->assertSame(1, count(array_keys($lines, 'revoked', true)), "round {$round}: ".json_encode($lines));
            $this->assertSame(0, DB::table(MachineTokens::TABLE)->where('subject', $subject)->count(), "round {$round}");
            $this->assertNull($this->tokens()->authenticate(self::kind(), $current));
            $this->assertNull($this->tokens()->authenticate(self::kind(), $pending));
        }
    }

    public function test_concurrent_issues_for_the_same_subject_leave_one_working_token(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $this->assertOneIssueWins("server:{$round}", "round {$round}");
        }
    }

    /**
     * 別々の対象に同時に出しても、失敗しない（設計書 10.1 の1つ目の推測）。
     * MySQL では、対象の条件の書き込みが合う行の無い所の索引の隙間をロックし、同時の INSERT どうしが行き詰まりうる
     */
    public function test_concurrent_issues_for_distinct_subjects_do_not_fail(): void
    {
        $failures = [];

        // 回ごとに4つのプロセスを並べ、それぞれ別の対象に1回だけ出す（対象は索引の同じ隙間に入る）
        for ($round = 0; $round < 30; $round++) {
            $lines = $this->fork(4, function (int $process) use ($round): string {
                $this->tokens()->issue(self::kind(), sprintf('server:%02d-%d', $round, $process));

                return 'ok';
            }, 0.3);
            $failures = [...$failures, ...array_filter($lines, fn ($l) => $l !== 'ok')];
            $this->assertCount(4, $lines, "round {$round}");
        }

        $this->assertSame([], array_values($failures));
        $this->assertSame(30 * 4, DB::table(MachineTokens::TABLE)->count());
    }

    /**
     * 分離の水準を上げても、競り負けた側は例外でなく、認めるべきものは認める
     * （PostgreSQL の REPEATABLE READ・SERIALIZABLE は、同じ行を先に書かれると 40001 で失敗する。確かめ・入れ替えは試し直す）
     */
    public function test_stricter_isolation_levels(): void
    {
        foreach (['repeatable read', 'serializable'] as $level) {
            $this->useIsolationLevel($level);
            for ($round = 1; $round <= 3; $round++) {
                $this->assertSwitchesOnce("{$level} round {$round}");
                $this->assertOneIssueWins("{$level}:{$round}", "{$level} issue round {$round}");
            }
        }
    }

    private function assertSwitchesOnce(string $label): void
    {
        [$current, $pending] = $this->rotated("server:{$label}");

        $lines = $this->fork(self::PROCESSES, function () use ($pending): string {
            return $this->tokens()->authenticate(self::kind(), $pending) === null ? 'refused' : 'ok';
        });

        $this->assertSame(array_fill(0, self::PROCESSES, 'ok'), $lines, "{$label}: ".json_encode($lines));
        $row = $this->row("server:{$label}");
        $this->assertSame(CodeFormat::hash($pending), $row->current_hash, $label);
        $this->assertSame(CodeFormat::hash($current), $row->previous_hash, $label);
        $this->assertNull($row->pending_hash, $label);
    }

    private function assertOneIssueWins(string $subject, string $label): void
    {
        $lines = $this->fork(4, function () use ($subject): string {
            return 'issued '.$this->tokens()->issue(self::kind(), $subject)->token;
        }, 0.5);

        $this->assertSame([], array_values(array_filter($lines, fn ($l) => ! str_starts_with($l, 'issued '))), "{$label}: ".json_encode($lines));
        $this->assertCount(4, $lines, $label);
        $this->assertSame(1, DB::table(MachineTokens::TABLE)->where('subject', $subject)->count(), $label);
        $working = array_filter($lines, fn ($l) => $this->tokens()->authenticate(self::kind(), substr($l, 7)) !== null);
        $this->assertCount(1, $working, $label);
    }

    private function row(string $subject): object
    {
        $row = DB::table(MachineTokens::TABLE)->where('subject', $subject)->first();
        $this->assertNotNull($row);

        return $row;
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
        $log = tempnam(sys_get_temp_dir(), 'mt-race');
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
