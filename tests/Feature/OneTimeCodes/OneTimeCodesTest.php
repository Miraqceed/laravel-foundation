<?php

namespace Miraqceed\Foundation\Tests\Feature\OneTimeCodes;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Miraqceed\Foundation\OneTimeCodes\ClaimedCode;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\Support\RefreshesOneTimeCodeDatabase;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 1回だけ使える合言葉：出す・受け取る・失効させる。合言葉はすべて作り物
 */
class OneTimeCodesTest extends TestCase
{
    use RefreshesOneTimeCodeDatabase;

    private OneTimeCodes $codes;

    private CodeKind $claimKind;

    private CodeKind $ticketKind;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.one_time_codes.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->codes = $this->app->make(OneTimeCodes::class);
        $this->claimKind = new CodeKind('token-claim', 'apc_');
        // 頭の印が同じ別の種類（種類の条件だけで分かれることを見る）
        $this->ticketKind = new CodeKind('ide-ticket', 'apc_', 60);
    }

    public function test_issued_code_can_be_claimed_once(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $issued = $this->codes->issue($this->claimKind, 'consumer:7:build-01', ['machine' => 'build-01']);

        $claimed = $this->codes->claim($this->claimKind, $issued->code);

        $this->assertInstanceOf(ClaimedCode::class, $claimed);
        $this->assertSame('token-claim', $claimed->kind);
        $this->assertSame('consumer:7:build-01', $claimed->subject);
        $this->assertSame(['machine' => 'build-01'], $claimed->data);
        $this->assertSame('2026-03-01 12:00:00', $claimed->issuedAt->format('Y-m-d H:i:s'));
        $this->assertNull($claimed->result);
        $this->assertNull($this->codes->claim($this->claimKind, $issued->code));
    }

    public function test_issued_code_starts_with_the_prefix_and_expires_after_the_ttl(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $issued = $this->codes->issue($this->ticketKind);

        $this->assertStringStartsWith('apc_', $issued->code);
        $this->assertSame('2026-03-01 12:01:00', $issued->expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_every_refusal_returns_null(): void
    {
        $this->travelTo('2026-03-01 12:00:00');

        // 無い（形は合う）
        $this->assertNull($this->codes->claim($this->claimKind, 'apc_'.str_repeat('Zz9', 13).'Q'));

        // 別の種類（同じ頭の印）
        $ticket = $this->codes->issue($this->ticketKind);
        $this->assertNull($this->codes->claim($this->claimKind, $ticket->code));
        $this->assertNotNull($this->codes->claim($this->ticketKind, $ticket->code));

        // 切れた（期限ちょうども断る）
        $atExpiry = $this->codes->issue($this->claimKind);
        $afterExpiry = $this->codes->issue($this->claimKind);
        $this->travelTo('2026-03-01 12:10:00');
        $this->assertNull($this->codes->claim($this->claimKind, $atExpiry->code));
        $this->travelTo('2026-03-01 12:10:01');
        $this->assertNull($this->codes->claim($this->claimKind, $afterExpiry->code));

        // 使った
        $used = $this->codes->issue($this->claimKind);
        $this->assertNotNull($this->codes->claim($this->claimKind, $used->code));
        $this->assertNull($this->codes->claim($this->claimKind, $used->code));

        // 失効した
        $revoked = $this->codes->issue($this->claimKind, 'consumer:7:build-02');
        $this->assertSame(1, $this->codes->revoke($this->claimKind, 'consumer:7:build-02'));
        $this->assertNull($this->codes->claim($this->claimKind, $revoked->code));
    }

    public function test_code_is_accepted_one_second_before_expiry(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $issued = $this->codes->issue($this->claimKind);

        $this->travelTo('2026-03-01 12:09:59');
        $this->assertNotNull($this->codes->claim($this->claimKind, $issued->code));
    }

    /** 形が違えば DB を引かない（後ろの改行・頭の印の違いは、DB を引いてもハッシュが違うので断られる。問い合わせの数で見る） */
    public function test_malformed_values_do_not_query_the_database(): void
    {
        $issued = $this->codes->issue($this->claimKind);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $body = substr($issued->code, strlen('apc_'));
        foreach ([$issued->code."\n", ' '.$issued->code, 'apd_'.$body, 'apc_'.substr($body, 1), [$issued->code], null, 42] as $value) {
            $this->assertNull($this->codes->claim($this->claimKind, $value));
        }

        $this->assertSame(0, $queries);
        // 断った後も、正しい合言葉は効いたまま
        $this->assertNotNull($this->codes->claim($this->claimKind, $issued->code));
    }

    public function test_then_runs_inside_the_claim_and_its_value_is_kept(): void
    {
        $issued = $this->codes->issue($this->claimKind, 'consumer:7:build-01');

        $before = DB::transactionLevel();
        $claimed = $this->codes->claim($this->claimKind, $issued->code, function (ClaimedCode $claimed) use ($before): string {
            $this->assertSame($before + 1, DB::transactionLevel());

            return 'issued-for:'.$claimed->subject;
        });

        $this->assertSame('issued-for:consumer:7:build-01', $claimed?->result);
    }

    /** $then が null や何も返さなくても、受け取れたことは断りと見分けられる */
    public function test_then_returning_null_still_counts_as_claimed(): void
    {
        $issued = $this->codes->issue($this->claimKind);

        $claimed = $this->codes->claim($this->claimKind, $issued->code, function (): void {});

        $this->assertInstanceOf(ClaimedCode::class, $claimed);
        $this->assertNull($claimed->result);
        $this->assertNull($this->codes->claim($this->claimKind, $issued->code));
    }

    public function test_exception_in_then_rolls_back_the_claim(): void
    {
        $issued = $this->codes->issue($this->claimKind);

        try {
            $this->codes->claim($this->claimKind, $issued->code, function (): never {
                throw new \RuntimeException('トークンを出せなかった（作り物）');
            });
            $this->fail('例外が出なかった');
        } catch (\RuntimeException $e) {
            $this->assertSame('トークンを出せなかった（作り物）', $e->getMessage());
        }

        $this->assertNotNull($this->codes->claim($this->claimKind, $issued->code));
    }

    public function test_reissue_revokes_the_previous_code_for_the_same_subject(): void
    {
        $first = $this->codes->issue($this->claimKind, 'consumer:7:build-01');
        $otherSubject = $this->codes->issue($this->claimKind, 'consumer:7:build-02');
        $otherKind = $this->codes->issue($this->ticketKind, 'consumer:7:build-01');
        $noSubject = $this->codes->issue($this->claimKind);

        $second = $this->codes->issue($this->claimKind, 'consumer:7:build-01');

        $this->assertNull($this->codes->claim($this->claimKind, $first->code));
        $this->assertNotNull($this->codes->claim($this->claimKind, $second->code));
        $this->assertNotNull($this->codes->claim($this->claimKind, $otherSubject->code));
        $this->assertNotNull($this->codes->claim($this->ticketKind, $otherKind->code));
        $this->assertNotNull($this->codes->claim($this->claimKind, $noSubject->code));
    }

    public function test_null_subject_does_not_revoke_anything(): void
    {
        $first = $this->codes->issue($this->claimKind);
        $second = $this->codes->issue($this->claimKind);

        $this->assertNotNull($this->codes->claim($this->claimKind, $first->code));
        $this->assertNotNull($this->codes->claim($this->claimKind, $second->code));
    }

    /** 失効させた数：使ったもの・切れたもの・失効したもの・別の種類・別の対象は数えない */
    public function test_revoke_returns_the_number_revoked(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $expired = $this->codes->issue($this->ticketKind, 'user:5');
        $this->travelTo('2026-03-01 12:05:00');
        $used = $this->codes->issue($this->claimKind, 'user:5');
        $this->codes->claim($this->claimKind, $used->code);
        $live = $this->codes->issue($this->claimKind, 'user:5');
        $this->codes->issue($this->claimKind, 'user:6');

        $this->assertSame(1, $this->codes->revoke($this->claimKind, 'user:5'));
        $this->assertSame(0, $this->codes->revoke($this->claimKind, 'user:5'));
        $this->assertSame(0, $this->codes->revoke($this->ticketKind, 'user:5'));
        $this->assertNull($this->codes->claim($this->claimKind, $live->code));
    }

    /** 出すときの失効と書き込みは1つのトランザクション。書き込みが失敗したら、前の合言葉は効いたまま */
    public function test_failed_insert_keeps_the_previous_code(): void
    {
        $previous = $this->codes->issue($this->claimKind, 'user:5');
        $armed = true;
        DB::beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower(ltrim($sql)), 'insert into')) {
                $armed = false;
                throw new \RuntimeException('書き込みの失敗（作り物）');
            }
        });

        try {
            $this->codes->issue($this->claimKind, 'user:5');
            $this->fail('例外が出なかった');
        } catch (\RuntimeException $e) {
            $this->assertSame('書き込みの失敗（作り物）', $e->getMessage());
        }

        $this->assertFalse($armed);
        $this->assertNotNull($this->codes->claim($this->claimKind, $previous->code));
    }

    /**
     * 対象・種類は、大文字・小文字・アクセント・後ろの空白の違いを別のものとして比べる
     * （MySQL の既定の照合順序 utf8mb4_unicode_ci・utf8mb4_0900_ai_ci は区別しない。utf8mb4_bin は後ろの空白を区別しない）
     */
    public function test_subjects_and_kinds_are_compared_exactly(): void
    {
        $others = [];
        foreach (['USER:ABC', 'usér:abc', 'user:abc '] as $subject) {
            $others[$subject] = $this->codes->issue($this->claimKind, $subject);
        }

        $this->codes->issue($this->claimKind, 'user:abc');

        $this->assertSame(1, $this->codes->revoke($this->claimKind, 'user:abc'));
        foreach ($others as $subject => $issued) {
            $this->assertNotNull($this->codes->claim($this->claimKind, $issued->code), "[{$subject}]");
        }

        $code = $this->codes->issue($this->claimKind)->code;
        $this->assertNull($this->codes->claim(new CodeKind('TOKEN-CLAIM', 'apc_'), $code));
        $this->assertNotNull($this->codes->claim($this->claimKind, $code));
    }

    /** 受け取った後の処理が投げても、例外の呼び出しの記録に平文の合言葉が残らない（投げた後も、合言葉はまた使える） */
    public function test_plain_code_is_not_kept_in_exception_traces(): void
    {
        if (filter_var(ini_get('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('zend.exception_ignore_args が有効（引数を記録しない）');
        }
        $issued = $this->codes->issue($this->claimKind);
        $body = substr($issued->code, strlen('apc_'));

        try {
            $this->codes->claim($this->claimKind, $issued->code, function (): never {
                throw new \RuntimeException('トークンを出せなかった（作り物）');
            });
            $this->fail('例外が出なかった');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(substr($issued->code, 0, 15), $e->getTraceAsString());
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    if (is_string($arg)) {
                        $this->assertStringNotContainsString($body, $arg);
                    }
                }
            }
        }
    }

    /** 平文を受ける引数には #[\SensitiveParameter] を付ける */
    public function test_parameters_holding_the_plain_code_are_sensitive(): void
    {
        $parameters = [
            [OneTimeCodes::class, 'claim', 'code'],
            [CodeFormat::class, 'matches', 'value'],
            [CodeFormat::class, 'hash', 'code'],
        ];
        foreach ($parameters as [$class, $method, $name]) {
            $parameter = array_values(array_filter(
                (new \ReflectionMethod($class, $method))->getParameters(),
                fn (\ReflectionParameter $p) => $p->getName() === $name,
            ))[0];
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), "{$method}(\${$name})");
        }
    }

    public function test_no_column_holds_the_plain_code(): void
    {
        $issued = $this->codes->issue($this->claimKind, 'consumer:7:build-01', ['machine' => 'build-01']);
        $body = substr($issued->code, strlen('apc_'));

        $row = (array) DB::table('foundation_one_time_codes')->first();
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($body, (string) $value, $column);
        }
        $this->assertSame(hash('sha256', $issued->code), $row['code_hash']);
    }

    public function test_subject_and_data_are_validated(): void
    {
        $invalid = [
            fn () => $this->codes->issue($this->claimKind, str_repeat('対', 256)),
            fn () => $this->codes->issue($this->claimKind, "kasou\xC3"),
            fn () => $this->codes->issue($this->claimKind, null, ['bad' => "kasou\xC3"]),
            fn () => $this->codes->issue($this->claimKind, null, ['nan' => NAN]),
        ];
        foreach ($invalid as $i => $issue) {
            try {
                $issue();
                $this->fail("例外が出なかった（{$i}）");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('kasou', $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('foundation_one_time_codes')->count());
        $this->assertSame(str_repeat('対', 255), $this->codes->claim(
            $this->claimKind,
            $this->codes->issue($this->claimKind, str_repeat('対', 255))->code,
        )?->subject);
    }
}
