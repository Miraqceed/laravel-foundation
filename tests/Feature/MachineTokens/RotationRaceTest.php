<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use Miraqceed\Foundation\Tests\Support\RefreshesMachineTokenDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 並びを決めた同時の要求：処理の最初の問い合わせの直後に、別の処理を割り込ませる。
 * 「読んでから、条件なしで書く（全部の列を書き戻す）」形だと、割り込んだ書き込みが消え、機械が締め出されうる。SQLite でも流れる
 */
class RotationRaceTest extends TestCase
{
    use RefreshesMachineTokenDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.machine_tokens.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));
    }

    private static function kind(): MachineKind
    {
        return new MachineKind('server-agent', 'sag_', 3600);
    }

    private function tokens(): MachineTokens
    {
        return $this->app->make(MachineTokens::class);
    }

    /** $work の最初の問い合わせの直後に $interleave を1回だけ動かす */
    private function interleave(\Closure $interleave, \Closure $work): mixed
    {
        $armed = true;
        DB::listen(function () use (&$armed, $interleave): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $interleave();
        });

        $result = $work();
        $this->assertFalse($armed, '割り込ませられなかった');

        return $result;
    }

    /** @return array{string, string} [今の鍵, 入れ替え待ちの鍵] */
    private function rotated(): array
    {
        $current = $this->tokens()->issue(self::kind(), 'server:1')->token;
        $pending = $this->tokens()->rotate(self::kind(), $current)?->token;
        $this->assertNotNull($pending);

        return [$current, $pending];
    }

    public function test_a_rotation_interleaved_after_the_switch_reads_the_row_wins(): void
    {
        [$current, $pending] = $this->rotated();

        $newer = null;
        $switched = $this->interleave(
            function () use (&$newer, $current): void {
                $newer = $this->tokens()->rotate(self::kind(), $current)?->token;
            },
            fn () => $this->tokens()->authenticate(self::kind(), $pending),
        );

        // 置き換わった入れ替え待ちでは切り替わらない。今の鍵は効き、割り込んだ入れ替えの鍵で切り替わる（締め出されない）
        $this->assertNotNull($newer);
        $this->assertNull($switched);
        $this->assertFalse($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
        $this->assertSame(CodeFormat::hash($newer), DB::table(MachineTokens::TABLE)->value('pending_hash'));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $newer));
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
    }

    public function test_a_switch_interleaved_after_rotate_reads_the_row_makes_rotate_fail(): void
    {
        [$current, $pending] = $this->rotated();

        $switched = null;
        $rotated = $this->interleave(
            function () use (&$switched, $pending): void {
                $switched = $this->tokens()->authenticate(self::kind(), $pending);
            },
            fn () => $this->tokens()->rotate(self::kind(), $current),
        );

        // 前の鍵になった鍵では入れ替えられない。新しい今の鍵が効き、入れ替え待ちは残らない
        $this->assertNotNull($switched);
        $this->assertNull($rotated);
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending));
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $current)?->viaPrevious);
        $this->assertNull(DB::table(MachineTokens::TABLE)->value('pending_hash'));
    }

    public function test_a_revoke_interleaved_after_the_switch_reads_the_row_wins(): void
    {
        [$current, $pending] = $this->rotated();

        $switched = $this->interleave(
            fn () => $this->assertTrue($this->tokens()->revoke(self::kind(), 'server:1')),
            fn () => $this->tokens()->authenticate(self::kind(), $pending),
        );

        $this->assertNull($switched);
        $this->assertNull($this->tokens()->authenticate(self::kind(), $current));
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending));
        $this->assertSame(0, DB::table(MachineTokens::TABLE)->count());
    }

    public function test_a_reissue_interleaved_after_the_switch_reads_the_row_wins(): void
    {
        [$current, $pending] = $this->rotated();

        $reissued = null;
        $switched = $this->interleave(
            function () use (&$reissued): void {
                $reissued = $this->tokens()->issue(self::kind(), 'server:1')->token;
            },
            fn () => $this->tokens()->authenticate(self::kind(), $pending),
        );

        $this->assertNull($switched);
        $this->assertNull($this->tokens()->authenticate(self::kind(), $current));
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $reissued));
        $this->assertNull(DB::table(MachineTokens::TABLE)->value('previous_hash'));
    }

    public function test_a_switch_interleaved_after_another_switch_reads_the_row_is_accepted_once(): void
    {
        [$current, $pending] = $this->rotated();

        $first = null;
        $second = $this->interleave(
            function () use (&$first, $pending): void {
                $first = $this->tokens()->authenticate(self::kind(), $pending);
            },
            fn () => $this->tokens()->authenticate(self::kind(), $pending),
        );

        // 両方とも認める（後の要求は読み直して、もう今の鍵だと分かる）。前の鍵は元の今の鍵のまま
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $row = DB::table(MachineTokens::TABLE)->first();
        $this->assertSame(CodeFormat::hash($pending), $row->current_hash);
        $this->assertSame(CodeFormat::hash($current), $row->previous_hash);
        $this->assertNull($row->pending_hash);
    }
}
