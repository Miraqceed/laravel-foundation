<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Miraqceed\Foundation\MachineTokens\IssuedToken;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use Miraqceed\Foundation\Tests\Support\RefreshesMachineTokenDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 出す・確かめる・失効（入れ替えは RotationTest） */
class MachineTokensTest extends TestCase
{
    use RefreshesMachineTokenDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.machine_tokens.enabled', true);
    }

    private static function kind(): MachineKind
    {
        return new MachineKind('server-agent', 'sag_', null);
    }

    private function tokens(): MachineTokens
    {
        return $this->app->make(MachineTokens::class);
    }

    public function test_issued_token_has_the_prefix_and_authenticates(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');

        $this->assertMatchesRegularExpression('/\Asag_[A-Za-z0-9]{40}\z/', $issued->token);
        $this->assertSame('server-agent', $issued->kind);
        $this->assertSame('server:1', $issued->subject);

        $machine = $this->tokens()->authenticate(self::kind(), $issued->token);
        $this->assertNotNull($machine);
        $this->assertSame('server:1', $machine->subject);
        $this->assertSame('server-agent', $machine->kind);
        $this->assertFalse($machine->shouldRotate);
        $this->assertFalse($machine->viaPrevious);
    }

    public function test_no_column_holds_the_plain_token(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');
        $rotated = $this->tokens()->rotate(self::kind(), $issued->token);
        $this->assertNotNull($rotated);

        foreach (DB::table(MachineTokens::TABLE)->get() as $row) {
            foreach ((array) $row as $value) {
                $this->assertStringNotContainsString($issued->token, (string) $value);
                $this->assertStringNotContainsString($rotated->token, (string) $value);
            }
        }
        $this->assertSame(CodeFormat::hash($issued->token), DB::table(MachineTokens::TABLE)->value('current_hash'));
        $this->assertSame(CodeFormat::hash($rotated->token), DB::table(MachineTokens::TABLE)->value('pending_hash'));
    }

    public function test_the_plain_token_is_not_dumped(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');

        $this->assertStringNotContainsString($issued->token, print_r($issued, true));
        ob_start();
        var_dump($issued);
        $this->assertStringNotContainsString($issued->token, (string) ob_get_clean());
    }

    public function test_the_plain_token_is_not_in_json(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');

        $json = json_encode(['issued' => $issued], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($issued->token, $json);
        $this->assertSame(['token' => '[伏せた]', 'kind' => 'server-agent', 'subject' => 'server:1'], json_decode($json, true)['issued']);
    }

    public function test_the_issued_token_cannot_be_serialized(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');

        try {
            serialize($issued);
            $this->fail('例外が出なかった');
        } catch (LogicException $e) {
            $this->assertStringNotContainsString($issued->token, $e->getMessage());
        }

        // 外から作った文字列で戻すこともできない
        $this->expectException(LogicException::class);
        unserialize('O:'.strlen(IssuedToken::class).':"'.IssuedToken::class.'":0:{}');
    }

    /** ログの context に渡しても、Monolog の正規化（JsonSerializable を使う）で平文が伏せられる。行の形と JSON の形の両方で */
    public function test_the_plain_token_is_not_written_by_the_logger(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');
        $path = sys_get_temp_dir().'/machine-tokens-log-'.bin2hex(random_bytes(6)).'.log';

        try {
            Log::build(['driver' => 'single', 'path' => $path])->info('出した', ['issued' => $issued]);
            $jsonHandler = new StreamHandler($path);
            $jsonHandler->setFormatter(new JsonFormatter);
            (new Logger('test', [$jsonHandler]))->info('出した', ['issued' => $issued]);

            $written = (string) file_get_contents($path);
            $this->assertSame(2, substr_count($written, '[伏せた]'), $written);
            $this->assertStringContainsString('server:1', $written);
            $this->assertStringNotContainsString($issued->token, $written);
        } finally {
            @unlink($path);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformed(): iterable
    {
        yield 'array' => [['sag_'.str_repeat('x', 40)]];
        yield 'integer' => [12345];
        yield 'null' => [null];
        yield 'trailing newline' => ['sag_'.str_repeat('x', 40)."\n"];
        yield '39 chars' => ['sag_'.str_repeat('x', 39)];
        yield '41 chars' => ['sag_'.str_repeat('x', 41)];
        yield 'other prefix' => ['wrk_'.str_repeat('x', 40)];
        yield 'symbol' => ['sag_'.str_repeat('x', 39).'-'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformed')]
    public function test_malformed_values_are_refused_without_querying(mixed $value): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertNull($this->tokens()->authenticate(self::kind(), $value));
        $this->assertNull($this->tokens()->rotate(self::kind(), $value));
        $this->assertSame(0, $queries);
    }

    public function test_unknown_and_other_kinds_are_refused(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');

        $this->assertNull($this->tokens()->authenticate(self::kind(), 'sag_'.str_repeat('x', 40)));
        // 同じ頭の印でも、別の種類の鍵は通らない
        $other = new MachineKind('backup-agent', 'sag_', null);
        $this->assertNull($this->tokens()->authenticate($other, $issued->token));
        $this->assertNull($this->tokens()->rotate($other, $issued->token));
        $this->assertFalse($this->tokens()->revoke($other, 'server:1'));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $issued->token));
    }

    public function test_reissue_replaces_every_token_of_the_subject_at_once(): void
    {
        $first = $this->tokens()->issue(self::kind(), 'server:1');
        $pending = $this->tokens()->rotate(self::kind(), $first->token);
        $this->assertNotNull($pending);
        $other = $this->tokens()->issue(self::kind(), 'server:2');

        $second = $this->tokens()->issue(self::kind(), 'server:1');

        $this->assertNull($this->tokens()->authenticate(self::kind(), $first->token));
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending->token));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $second->token));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $other->token));
        $this->assertSame(1, DB::table(MachineTokens::TABLE)->where('subject', 'server:1')->count());
    }

    public function test_reissue_after_rotation_also_drops_the_previous_token(): void
    {
        $first = $this->tokens()->issue(self::kind(), 'server:1');
        $pending = $this->tokens()->rotate(self::kind(), $first->token);
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $pending->token));   // 切り替わり、first は前の鍵
        $this->assertTrue($this->tokens()->authenticate(self::kind(), $first->token)?->viaPrevious);

        $this->tokens()->issue(self::kind(), 'server:1');

        $this->assertNull($this->tokens()->authenticate(self::kind(), $first->token));
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending->token));
    }

    public function test_revoke_removes_the_row_and_every_token(): void
    {
        $issued = $this->tokens()->issue(self::kind(), 'server:1');
        $pending = $this->tokens()->rotate(self::kind(), $issued->token);

        $this->assertTrue($this->tokens()->revoke(self::kind(), 'server:1'));

        $this->assertNull($this->tokens()->authenticate(self::kind(), $issued->token));
        $this->assertNull($this->tokens()->authenticate(self::kind(), $pending->token));
        $this->assertSame(0, DB::table(MachineTokens::TABLE)->count());
        $this->assertFalse($this->tokens()->revoke(self::kind(), 'server:1'));
    }

    public function test_subjects_are_compared_exactly(): void
    {
        $upper = $this->tokens()->issue(self::kind(), 'server:A');
        $lower = $this->tokens()->issue(self::kind(), 'server:a');

        $this->assertSame('server:A', $this->tokens()->authenticate(self::kind(), $upper->token)?->subject);
        $this->assertSame('server:a', $this->tokens()->authenticate(self::kind(), $lower->token)?->subject);
        $this->assertTrue($this->tokens()->revoke(self::kind(), 'server:a'));
        $this->assertNotNull($this->tokens()->authenticate(self::kind(), $upper->token));
    }

    public function test_bad_subjects_are_refused_without_the_value_in_the_message(): void
    {
        foreach (['', str_repeat('あ', 256), "\xff\xfe"] as $subject) {
            try {
                $this->tokens()->issue(self::kind(), $subject);
                $this->fail('例外が出なかった');
            } catch (InvalidArgumentException $e) {
                if ($subject !== '') {
                    $this->assertStringNotContainsString($subject, $e->getMessage());
                }
            }
        }
        $this->assertSame(0, DB::table(MachineTokens::TABLE)->count());
        $this->assertNotNull($this->tokens()->issue(self::kind(), str_repeat('あ', 255)));
    }

    public function test_times_are_written_in_utc_whatever_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $default = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));
            $this->tokens()->issue(self::kind(), 'server:1');

            $this->assertSame('2026-03-01 12:00:00', substr((string) DB::table(MachineTokens::TABLE)->value('current_issued_at'), 0, 19));
        } finally {
            date_default_timezone_set($default);
        }
    }
}
