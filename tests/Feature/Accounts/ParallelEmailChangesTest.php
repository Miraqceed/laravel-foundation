<?php

namespace Miraqceed\Foundation\Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Miraqceed\Foundation\Accounts\EmailChanges;
use Miraqceed\Foundation\Accounts\EmailChangeStatus;
use Miraqceed\Foundation\Tests\Support\CreatesEmailChangeTables;
use Miraqceed\Foundation\Tests\Support\EmailChangeUser;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の要求（別々のプロセス）。PostgreSQL・MySQL で流す（SQLite は書き込みを1つずつ行うので、並べても同時にならない）。
 * 表は子から見えるよう、トランザクションの外で作って書く
 */
class ParallelEmailChangesTest extends TestCase
{
    use CreatesEmailChangeTables;

    private const PROCESSES = 10;

    private EmailChangeUser $alice;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', EmailChangeUser::class);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }

        $this->createEmailChangeTables();
        $this->alice = EmailChangeUser::create(['email' => 'alice@example.test', 'password' => Hash::make('alice-password')]);
    }

    protected function tearDown(): void
    {
        if (isset($this->alice)) {
            $this->dropEmailChangeTables();
        }

        parent::tearDown();
    }

    private function changes(): EmailChanges
    {
        return $this->app->make(EmailChanges::class);
    }

    public function test_同じリンクを同時に確かめても切り替えは1回(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $requested = $this->changes()->request($this->alice->fresh(), "alice{$round}@example.test");

            $lines = $this->fork(self::PROCESSES, function () use ($requested): string {
                return $this->changes()->confirm($requested->id, $requested->token, EmailChangeUser::query()->find($this->alice->id))->status->value;
            });

            $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))), "round {$round}");
            $this->assertSame(1, count(array_keys($lines, 'confirmed', true)), "round {$round}: ".json_encode($lines));
            $this->assertSame(self::PROCESSES - 1, count(array_keys($lines, 'used', true)), "round {$round}");
            $this->assertSame("alice{$round}@example.test", (string) $this->alice->fresh()->email);
        }
    }

    /**
     * 戻すと次の変更の確かめを同時に：どちらが先でも、終わりは元のアドレスで、戻した印が付き、どちらも例外にならない
     */
    public function test_戻すと次の変更の確かめを同時にしても_元のアドレスに戻る(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            DB::table('users')->where('id', $this->alice->id)->update(['email' => 'alice@example.test']);
            $first = $this->changes()->request($this->alice->fresh(), "b{$round}@example.test");
            $undoToken = (string) $this->changes()->confirm($first->id, $first->token, $this->alice->fresh())->undoToken;
            $second = $this->changes()->request($this->alice->fresh(), "c{$round}@example.test");

            $lines = $this->fork(2, function (int $process) use ($first, $undoToken, $second): string {
                return $process === 0
                    ? 'undo '.$this->changes()->undo($first->id, $undoToken)->status->value
                    : 'confirm '.$this->changes()->confirm($second->id, $second->token, EmailChangeUser::query()->find($this->alice->id))->status->value;
            });

            $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))), "round {$round}");
            $this->assertContains('undo undone', $lines, "round {$round}: ".json_encode($lines));
            $this->assertSame('alice@example.test', (string) $this->alice->fresh()->email, "round {$round}: ".json_encode($lines));
            $this->assertNotNull(DB::table(EmailChanges::TABLE)->where('id', $first->id)->value('undone_at'));
            // 確かめが後なら取り消されている。先なら、たどって一緒に戻されている
            $this->assertTrue(
                in_array('confirm cancelled', $lines, true)
                    || (in_array('confirm confirmed', $lines, true) && DB::table(EmailChanges::TABLE)->where('id', $second->id)->value('undone_at') !== null),
                "round {$round}: ".json_encode($lines),
            );
            $this->assertNotContains(EmailChangeStatus::Changed->value, $lines);
        }
    }

    /**
     * 別々のプロセスを同じ時刻に始めさせ、それぞれの結果を1行で返させる（例外は "e 種類: 文"）
     *
     * @param  \Closure(int): string  $work
     * @return list<string>
     */
    private function fork(int $processes, \Closure $work): array
    {
        $log = tempnam(sys_get_temp_dir(), 'email-change-race');
        // 子は親の接続を受け継がず、それぞれつなぎ直す
        DB::disconnect();
        $start = microtime(true) + 1.0;
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
