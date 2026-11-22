<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Miraqceed\Foundation\Tests\Support\RefreshesMachineTokenDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/** 入れ替え：入れ替え待ち・初めての使用での切り替え・前の鍵の猶予・入れ替え待ちの期限・促し・最後に使った時刻 */
class RotationTest extends TestCase
{
    use RefreshesMachineTokenDatabase;

    private const START = '2026-03-01 12:00:00';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.machine_tokens.enabled', true);
    }

    private static function kind(?int $rotateAfter = 3600, int $grace = 600, int $pending = 300): MachineKind
    {
        return new MachineKind('server-agent', 'sag_', $rotateAfter, $grace, $pending);
    }

    private function tokens(): MachineTokens
    {
        return $this->app->make(MachineTokens::class);
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
    }

    public function test_the_current_token_keeps_working_until_the_pending_one_is_first_used(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertNotNull($pending);

        // 入れ替えの答えを受けた後も、今の鍵は効く
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);

        // 入れ替え待ちを初めて使うと切り替わる。前の鍵は猶予の間だけ効く
        $this->at('2026-03-01 12:01:00');
        $machine = $this->tokens()->authenticate(self::kind(), $pending);
        $this->assertNotNull($machine);
        $this->assertFalse($machine->viaPrevious);
        $this->assertSame('server:1', $machine->subject);

        $this->at('2026-03-01 12:10:59');
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
        $this->at('2026-03-01 12:11:00');   // 切り替えから 600秒ちょうど
        $this->assertNull($this->tokens()->authenticate(self::kind(), $current));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending));
    }

    public function test_a_lost_rotation_answer_never_locks_the_machine_out(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;

        // 1回目の答えが届かなかった：今の鍵は効き、もう一度入れ替えられる
        $lost = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $current));
        $second = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertNotNull($second);

        $this->assertNull($this->tokens()->authenticate(self::kind(), $lost));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $current));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $second));
        $this->assertSame(\Miraqceed\Foundation\OneTimeCodes\CodeFormat::hash($second), DB::table(MachineTokens::TABLE)->value('current_hash'));
    }

    public function test_only_the_current_token_can_rotate(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;

        // 入れ替え待ちの鍵では入れ替えられない
        $this->assertNull($this->tokens()->rotate(self::kind(), $pending));

        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending));
        // 前の鍵（漏れたかもしれない）では入れ替えられない
        $this->assertNull($this->tokens()->rotate(self::kind(), $current));
        $this->assertNotNull($this->tokens()->rotate(self::kind(), $pending));

        $this->tokens()->revoke(self::kind(), 'server:1');
        $this->assertNull($this->tokens()->rotate(self::kind(), $pending));
    }

    public function test_an_unused_pending_token_expires_and_the_current_one_keeps_working(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $this->at('2026-03-01 13:00:00');
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;

        $this->at('2026-03-01 13:04:59');
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->shouldRotate, '期限の内の入れ替え待ちがあれば促さない');

        $this->at('2026-03-01 13:05:00');   // 入れ替え待ちを置いてから 300秒ちょうど
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending));
        $machine = $this->tokens()->authenticate(self::kind(), $current);
        $this->assertNotNull($machine);
        $this->assertTrue($machine->shouldRotate, '入れ替え待ちの期限が切れたら、また促す');
        $this->assertNotNull($this->tokens()->rotate(self::kind(), $current));
    }

    public function test_with_no_grace_the_previous_token_stops_at_the_switch(): void
    {
        $kind = self::kind(grace: 0);
        $this->at(self::START);
        $current = $this->tokens()->issue($kind, 'server:1')->token;
        $pending = $this->tokens()->rotate($kind, $current)?->token;

        $this->assertNotNull($this->tokens()->authenticate($kind, $pending));
        $this->assertNull($this->tokens()->authenticate($kind, $current));
    }

    public function test_should_rotate(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;

        $this->at('2026-03-01 12:59:59');
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->shouldRotate);
        $this->at('2026-03-01 13:00:00');   // 出してから 3600秒ちょうど
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $current)?->shouldRotate);
        // 促さない種類
        $this->assertFalse($this->tokens()->authenticate(self::kind(rotateAfter: null), $current)?->shouldRotate);

        // 切り替えた時から数え直す。前の鍵では促さない
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $pending)?->shouldRotate);
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->shouldRotate);
        $this->at('2026-03-01 13:59:59');
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $pending)?->shouldRotate);
        $this->at('2026-03-01 14:00:00');
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $pending)?->shouldRotate);
    }

    public function test_last_used_at_is_written_at_most_once_a_minute(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $this->assertNull(DB::table(MachineTokens::TABLE)->value('last_used_at'));

        $this->assertSame(1, $this->updatesDuring(fn () => $this->tokens()->authenticate(self::kind(), $current)));
        $this->assertSame(self::START, $this->lastUsedAt());

        $this->at('2026-03-01 12:00:59');
        $this->assertSame(0, $this->updatesDuring(fn () => $this->tokens()->authenticate(self::kind(), $current)));
        $this->assertSame(self::START, $this->lastUsedAt());

        $this->at('2026-03-01 12:01:00');
        $this->assertSame(1, $this->updatesDuring(fn () => $this->tokens()->authenticate(self::kind(), $current)));
        $this->assertSame('2026-03-01 12:01:00', $this->lastUsedAt());

        // 切り替えでは必ず書く
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->at('2026-03-01 12:01:30');
        $this->tokens()->authenticate(self::kind(), $pending);
        $this->assertSame('2026-03-01 12:01:30', $this->lastUsedAt());
    }

    public function test_rolling_back_the_app_transaction_undoes_the_switch_and_keeps_the_pending_token(): void
    {
        $this->at(self::START);
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;

        try {
            DB::transaction(function () use ($pending): void {
                $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending));
                throw new RuntimeException('アプリの処理が落ちた');
            });
        } catch (RuntimeException) {
        }

        // 切り替えは巻き戻り、入れ替え待ちのまま。今の鍵も効く。もう一度使えば切り替わる
        $this->assertNotNull(DB::table(MachineTokens::TABLE)->value('pending_hash'));
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending));
        $this->assertNull(DB::table(MachineTokens::TABLE)->value('pending_hash'));
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
    }

    private function updatesDuring(\Closure $work): int
    {
        $updates = 0;
        $listening = true;
        DB::listen(function ($query) use (&$updates, &$listening): void {
            if ($listening && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updates++;
            }
        });
        $work();
        $listening = false;

        return $updates;
    }

    private function lastUsedAt(): ?string
    {
        $value = DB::table(MachineTokens::TABLE)->value('last_used_at');

        return $value === null ? null : substr((string) $value, 0, 19);
    }
}
