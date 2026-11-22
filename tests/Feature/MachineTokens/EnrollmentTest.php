<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\MachineTokens\MachineKind;
use Miraqceed\Foundation\MachineTokens\MachineTokens;
use Miraqceed\Foundation\OneTimeCodes\ClaimedCode;
use Miraqceed\Foundation\OneTimeCodes\CodeKind;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;
use Miraqceed\Foundation\Tests\Support\RefreshesMachineTokenDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/** 最初の鍵の渡し方：1回だけ使える合言葉を受け取った後の処理の中で、機械の鍵を出す */
class EnrollmentTest extends TestCase
{
    use RefreshesMachineTokenDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.machine_tokens.enabled', true);
        $app['config']->set('foundation.one_time_codes.enabled', true);
    }

    private static function codeKind(): CodeKind
    {
        return new CodeKind('agent-enroll', 'enr_', 600);
    }

    private static function machineKind(): MachineKind
    {
        return new MachineKind('server-agent', 'sag_', 86_400);
    }

    public function test_a_claimed_code_is_exchanged_for_the_first_machine_token(): void
    {
        $code = $this->app->make(OneTimeCodes::class)->issue(self::codeKind(), 'server:7')->code;

        $claimed = $this->app->make(OneTimeCodes::class)->claim(self::codeKind(), $code, fn (ClaimedCode $c) => $this->app->make(MachineTokens::class)->issue(self::machineKind(), (string) $c->subject)->token);

        $this->assertNotNull($claimed);
        $machine = $this->app->make(MachineTokens::class)->authenticate(self::machineKind(), $claimed->result);
        $this->assertSame('server:7', $machine?->subject);
        $this->assertNull($this->app->make(OneTimeCodes::class)->claim(self::codeKind(), $code));
    }

    public function test_a_failure_after_issuing_keeps_the_code_claimable_and_leaves_no_token(): void
    {
        $code = $this->app->make(OneTimeCodes::class)->issue(self::codeKind(), 'server:7')->code;
        $issued = null;

        try {
            $this->app->make(OneTimeCodes::class)->claim(self::codeKind(), $code, function (ClaimedCode $c) use (&$issued): void {
                $issued = $this->app->make(MachineTokens::class)->issue(self::machineKind(), (string) $c->subject)->token;
                throw new RuntimeException('答えを作れなかった');
            });
            $this->fail('例外が出なかった');
        } catch (RuntimeException) {
        }

        $this->assertNotNull($issued);
        $this->assertNull($this->app->make(MachineTokens::class)->authenticate(self::machineKind(), $issued));
        $this->assertSame(0, DB::table(MachineTokens::TABLE)->count());

        // 合言葉はまた受け取れる
        $claimed = $this->app->make(OneTimeCodes::class)->claim(self::codeKind(), $code, fn (ClaimedCode $c) => $this->app->make(MachineTokens::class)->issue(self::machineKind(), (string) $c->subject)->token);
        $this->assertNotNull($this->app->make(MachineTokens::class)->authenticate(self::machineKind(), $claimed?->result));
    }
}
