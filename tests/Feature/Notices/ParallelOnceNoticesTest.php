<?php

namespace Miraqceed\Foundation\Tests\Feature\Notices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Notices\NoticeClaim;
use Miraqceed\Foundation\Notices\OnceNotices;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 本物の同時の取り（別々のプロセス）。PostgreSQL・MySQL で流す（SQLite は書き込みを1つずつ行うので、並べても同時にならない）。
 * 表は子から見えるよう、トランザクションの外で作って書く
 */
class ParallelOnceNoticesTest extends TestCase
{
    private const PROCESSES = 10;

    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl と posix が要る');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('PostgreSQL・MySQL で流す');
        }

        Schema::dropIfExists(OnceNotices::TABLE);
        $migration = require __DIR__.'/../../../database/notice-migrations/2026_11_20_000000_create_foundation_notices_table.php';
        $migration->up();
        $this->created = true;
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            Schema::dropIfExists(OnceNotices::TABLE);
        }

        parent::tearDown();
    }

    private function notices(): OnceNotices
    {
        return $this->app->make(OnceNotices::class);
    }

    public function test_同じ鍵を同時に送っても_送るのは1回(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $key = "usage-80:42:round-{$round}";

            $lines = $this->fork(self::PROCESSES, function () use ($key): string {
                return $this->notices()->send($key, function (): void {
                    // 送っている間に、ほかのプロセスが確かめに来る
                    usleep(50_000);
                })->value;
            });

            $this->assertNoErrors($lines, $round);
            $this->assertSame(1, count(array_keys($lines, 'sent', true)), "round {$round}: ".json_encode($lines));
            $this->assertSame(self::PROCESSES - 1, count(array_keys($lines, 'already_sent', true)), "round {$round}");
            $this->assertSame(1, DB::table(OnceNotices::TABLE)->where('key', $key)->whereNotNull('sent_at')->count());
        }
    }

    public function test_止まった印を同時に取り直しても_取れるのは1つ(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $key = "grace-end:7:round-{$round}";
            $this->staleClaim($key);

            $lines = $this->fork(self::PROCESSES, fn (): string => $this->notices()->claim($key) === null ? 'none' : 'claimed');

            $this->assertNoErrors($lines, $round);
            $this->assertSame(1, count(array_keys($lines, 'claimed', true)), "round {$round}: ".json_encode($lines));
        }
    }

    /**
     * キューで待っていた前のジョブの holds() と、次の確かめの取り直しを同時に：どちらが先でも、送るのは1つ
     */
    public function test_前のジョブの_holds_と取り直しが同時でも_送るのは1つ(): void
    {
        for ($round = 1; $round <= 5; $round++) {
            $key = "trial-end:9:round-{$round}";
            $old = $this->staleClaim($key);

            $lines = $this->fork(2, function (int $process) use ($key, $old): string {
                if ($process === 0) {
                    return $old->holds() && $old->sent() ? 'old sent' : 'old skipped';
                }

                $new = $this->notices()->claim($key);

                return $new !== null && $new->holds() && $new->sent() ? 'new sent' : 'new skipped';
            });

            $this->assertNoErrors($lines, $round);
            $sent = array_values(array_filter($lines, fn (string $line) => str_ends_with($line, ' sent')));
            $this->assertCount(1, $sent, "round {$round}: ".json_encode($lines));
        }
    }

    /** 20分前に取ったまま送っていない印（取り直しの分の15分を過ぎている） */
    private function staleClaim(string $key): NoticeClaim
    {
        $claimedAt = CarbonImmutable::now('UTC')->subMinutes(20)->format('Y-m-d H:i:s');
        $token = str_repeat('a', 32);

        DB::table(OnceNotices::TABLE)->insert([
            'key' => $key,
            'key_hash' => hash('sha256', $key),
            'token' => $token,
            'claimed_at' => $claimedAt,
            'sent_at' => null,
            'keep_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d H:i:s'),
        ]);

        return new NoticeClaim($key, $token);
    }

    /** @param  list<string>  $lines */
    private function assertNoErrors(array $lines, int $round): void
    {
        $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'e '))), "round {$round}");
    }

    /**
     * 別々のプロセスを同じ時刻に始めさせ、それぞれの結果を1行で返させる（例外は "e 種類: 文"）
     *
     * @param  \Closure(int): string  $work
     * @return list<string>
     */
    private function fork(int $processes, \Closure $work): array
    {
        $log = tempnam(sys_get_temp_dir(), 'once-notice-race');
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

        $this->assertCount($processes, $lines, '子のプロセスが結果を書かなかった');

        return $lines;
    }
}
