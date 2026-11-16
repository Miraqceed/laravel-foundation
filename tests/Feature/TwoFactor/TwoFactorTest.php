<?php

namespace Miraqceed\Foundation\Tests\Feature\TwoFactor;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\Support\TwoFactorAdmin;
use Miraqceed\Foundation\Tests\Support\TwoFactorUser;
use Miraqceed\Foundation\Tests\TestCase;
use Miraqceed\Foundation\TwoFactor\Totp;
use Miraqceed\Foundation\TwoFactor\TwoFactor;
use Miraqceed\Foundation\TwoFactor\TwoFactorLocked;

/**
 * 手書きの認証のアプリ向けの二段階の認証。利用者の表の3つの列を、条件つきの UPDATE で書く
 */
class TwoFactorTest extends TestCase
{
    /** 刻みのちょうど中ほどの時刻 */
    private const TIME = 1795136415;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        // 試しでは上限を小さくする
        $app['config']->set('foundation.attempts.consecutive', ['max' => 3, 'lock_seconds' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
        });
        $this->migrate();
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(self::TIME));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /** 写して使うマイグレーションを、そのまま流す */
    private function migrate(): void
    {
        $files = glob(__DIR__.'/../../../database/two-factor-migrations/*.php');
        $this->assertCount(1, $files);
        (require $files[0])->up();
    }

    private function user(): TwoFactorUser
    {
        return TwoFactorUser::query()->create(['email' => 'a@example.test']);
    }

    private function twoFactor(): TwoFactor
    {
        return $this->app->make(TwoFactor::class);
    }

    private function codeAt(string $secret, int $offsetSteps = 0): string
    {
        return Totp::codeAt($secret, self::TIME + 30 * $offsetSteps);
    }

    private function confirmed(): array
    {
        $user = $this->user();
        $secret = Totp::generateSecret();
        $this->assertTrue($this->twoFactor()->confirm($user, $secret, $this->codeAt($secret, -1)));

        return [$user->fresh(), $secret];
    }

    private function row(TwoFactorUser $user): object
    {
        return DB::table('users')->where('id', $user->getKey())->first();
    }

    public function test_migration_is_published_under_its_own_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(FoundationServiceProvider::class, 'foundation-two-factor-migrations');

        $this->assertCount(1, $paths);
        $this->assertDirectoryExists(array_key_first($paths));
    }

    public function test_migration_adds_only_missing_columns(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            // Fortify の2つの列が既にある
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        $this->migrate();

        $this->assertTrue(Schema::hasColumns('users', ['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step']));
    }

    public function test_confirm_stores_the_secret_encrypted_and_the_step_used(): void
    {
        $user = $this->user();
        $secret = Totp::generateSecret();

        $this->assertTrue($this->twoFactor()->confirm($user, $secret, $this->codeAt($secret)));

        $row = $this->row($user);
        $this->assertStringNotContainsString($secret, (string) $row->two_factor_secret);
        $this->assertSame($secret, Crypt::decryptString($row->two_factor_secret));
        $this->assertNotNull($row->two_factor_confirmed_at);
        $this->assertSame(intdiv(self::TIME, 30), (int) $row->two_factor_last_step);
        $this->assertTrue($this->twoFactor()->isConfirmed($user));
    }

    public function test_the_code_used_to_confirm_cannot_be_used_to_log_in(): void
    {
        $user = $this->user();
        $secret = Totp::generateSecret();
        $code = $this->codeAt($secret);
        $this->assertTrue($this->twoFactor()->confirm($user, $secret, $code));

        $this->assertFalse($this->twoFactor()->verify($user->fresh(), $code));
    }

    public function test_verify_accepts_a_code_once(): void
    {
        [$user, $secret] = $this->confirmed();
        $code = $this->codeAt($secret);

        $this->assertTrue($this->twoFactor()->verify($user, $code));
        $this->assertFalse($this->twoFactor()->verify($user->fresh(), $code));
    }

    public function test_verify_accepts_the_next_step(): void
    {
        [$user, $secret] = $this->confirmed();
        $this->assertTrue($this->twoFactor()->verify($user, $this->codeAt($secret)));

        $this->travel(30)->seconds();
        $this->assertTrue($this->twoFactor()->verify($user->fresh(), $this->codeAt($secret, 1)));
    }

    /**
     * 同じコードの2つの要求が、どちらも書く前の last_step を読んだ後に揃う形。
     * 1つ目が最後に受けた刻みを読んだ所（利用者の表の2回目の SELECT）で、2つ目を最後まで走らせる。
     * 条件つきの UPDATE（last_step より後のときだけ書く）なら、1つ目の書き込みは1行も変えずに断られる
     */
    public function test_two_requests_that_read_the_same_last_step_pass_only_once(): void
    {
        [$user, $secret] = $this->confirmed();
        $code = $this->codeAt($secret);
        $results = [];
        $selects = 0;
        $raced = false;
        $lastStepSeenBySecond = null;

        DB::listen(function ($query) use (&$selects, &$raced, &$results, &$lastStepSeenBySecond, $user, $code) {
            if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'users')) {
                return;
            }
            if (++$selects === 2) {
                $raced = true;
                $lastStepSeenBySecond = (int) $this->row($user)->two_factor_last_step;
                $results['second'] = $this->twoFactor()->verify(TwoFactorUser::query()->find($user->getKey()), $code);
            }
        });

        $results['first'] = $this->twoFactor()->verify($user, $code);

        // 起きたことを先に確かめる：2つ目は、1つ目が書く前の last_step を読んで走った
        $this->assertTrue($raced);
        $this->assertSame(intdiv(self::TIME, 30) - 1, $lastStepSeenBySecond);
        $this->assertSame(['second' => true, 'first' => false], $results);
    }

    public function test_two_sessions_confirming_different_secrets_confirm_only_once(): void
    {
        $user = $this->user();
        $one = Totp::generateSecret();
        $two = Totp::generateSecret();

        $this->assertTrue($this->twoFactor()->confirm($user, $one, $this->codeAt($one)));
        $this->assertFalse($this->twoFactor()->confirm($user, $two, $this->codeAt($two)));

        $this->assertSame($one, Crypt::decryptString($this->row($user)->two_factor_secret));
    }

    public function test_reset_clears_all_columns_and_allows_confirming_again(): void
    {
        [$user] = $this->confirmed();

        $this->twoFactor()->reset($user);

        $row = $this->row($user);
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_confirmed_at);
        $this->assertNull($row->two_factor_last_step);
        $this->assertFalse($this->twoFactor()->isConfirmed($user));
        $again = Totp::generateSecret();
        $this->assertTrue($this->twoFactor()->confirm($user, $again, $this->codeAt($again)));
    }

    public function test_wrong_codes_are_counted_as_consecutive_failures_and_lock(): void
    {
        [$user, $secret] = $this->confirmed();
        $wrong = sprintf('%06d', ((int) $this->codeAt($secret) + 500000) % 1000000);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->twoFactor()->verify($user, $wrong));
        }

        $this->expectException(TooManyAttempts::class);
        // ロック中は、正しいコードでも確かめない
        $this->twoFactor()->verify($user, $this->codeAt($secret));
    }

    public function test_mistakes_while_confirming_count_too(): void
    {
        $user = $this->user();
        $secret = Totp::generateSecret();

        $this->assertFalse($this->twoFactor()->confirm($user, $secret, '000000'));
        $this->assertFalse($this->twoFactor()->confirm($user, $secret, '111111'));
        $this->assertFalse($this->twoFactor()->confirm($user, $secret, '222222'));

        $this->expectException(TooManyAttempts::class);
        $this->twoFactor()->confirm($user, $secret, $this->codeAt($secret));
    }

    /**
     * 形の違う入力（5桁・文字）も失敗に数える。数えないと、形を変えながら何度でも送れる
     */
    public function test_malformed_input_counts_as_a_failure(): void
    {
        [$user, $secret] = $this->confirmed();

        $this->assertFalse($this->twoFactor()->verify($user, '12345'));
        $this->assertFalse($this->twoFactor()->verify($user, 'abcdef'));
        $this->assertFalse($this->twoFactor()->verify($user, ''));

        $this->expectException(TooManyAttempts::class);
        $this->twoFactor()->verify($user, $this->codeAt($secret));
    }

    public function test_dispatches_locked_event_without_the_secret_or_code(): void
    {
        Event::fake([TwoFactorLocked::class]);
        [$user, $secret] = $this->confirmed();

        $this->twoFactor()->verify($user, '000001');
        $this->twoFactor()->verify($user, '000002');
        Event::assertNotDispatched(TwoFactorLocked::class);
        $this->twoFactor()->verify($user, '000003');

        Event::assertDispatched(TwoFactorLocked::class, function (TwoFactorLocked $event) use ($user, $secret): bool {
            $this->assertSame($user->getKey(), $event->user->getKey());
            $this->assertStringNotContainsString($secret, serialize([$event->user->getKey()]));

            return true;
        });
    }

    public function test_unconfirmed_user_cannot_verify(): void
    {
        $user = $this->user();

        $this->assertFalse($this->twoFactor()->verify($user, '123456'));
    }

    public function test_uri_uses_the_app_name_as_issuer_by_default(): void
    {
        config(['app.name' => '架空アプリ', 'foundation.two_factor.issuer' => null]);

        $uri = $this->twoFactor()->uri('a@example.test', 'JBSWY3DPEHPK3PXP');

        $this->assertStringContainsString('issuer='.rawurlencode('架空アプリ'), $uri);
    }

    /** users と別の表（admins）に、同じ列を足す */
    private function makeAdmins(): void
    {
        Schema::dropIfExists('admins');
        Schema::create('admins', function ($table) {
            $table->id();
            $table->string('email');
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->bigInteger('two_factor_last_step')->nullable();
        });
    }

    /**
     * 表はモデルの表（getTable()）で決める。users と別の表のモデルが、同じ id の users の行を読み書きしない。
     * 数えと通った印も、表ごとに分かれる
     */
    public function test_models_of_different_tables_do_not_share_rows_or_counts(): void
    {
        $this->makeAdmins();
        [$user, $secret] = $this->confirmed();
        $admin = TwoFactorAdmin::query()->create(['email' => 'admin@example.test']);
        // 前提：同じ id
        $this->assertSame($user->getKey(), $admin->getKey());

        $this->assertFalse($this->twoFactor()->isConfirmed($admin));
        $this->assertFalse($this->twoFactor()->verify($admin, $this->codeAt($secret)));

        // 利用者の失敗の数えは、管理者に効かない
        $wrong = sprintf('%06d', ((int) $this->codeAt($secret) + 500000) % 1000000);
        for ($i = 0; $i < 3; $i++) {
            $this->twoFactor()->verify($user, $wrong);
        }
        $adminSecret = Totp::generateSecret();
        $this->assertTrue($this->twoFactor()->confirm($admin, $adminSecret, $this->codeAt($adminSecret)));
        $this->assertSame($secret, Crypt::decryptString(DB::table('users')->where('id', $user->getKey())->value('two_factor_secret')));
    }

    /**
     * 並んだ総当たりで、1 回のロックにつき、ロックの出来事は1回だけ出る。
     * 1つ目の要求が照合を始めた所（利用者の表の2回目の SELECT）で、ほかの要求を走らせて上限に届かせ、その後で1つ目が失敗する
     */
    public function test_locked_event_is_dispatched_once_per_lock_even_when_attempts_overlap(): void
    {
        Event::fake([TwoFactorLocked::class]);
        [$user] = $this->confirmed();
        $selects = 0;
        $raced = false;
        DB::listen(function ($query) use (&$selects, &$raced, $user) {
            if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'users')) {
                return;
            }
            if (++$selects === 2) {
                $raced = true;
                // ほかの2つの要求が失敗し、2つ目で上限に届いてロックする
                $this->twoFactor()->verify(TwoFactorUser::query()->find($user->getKey()), '000001');
                $this->twoFactor()->verify(TwoFactorUser::query()->find($user->getKey()), '000002');
            }
        });

        $this->assertFalse($this->twoFactor()->verify($user, '000003'));

        $this->assertTrue($raced);
        Event::assertDispatchedTimes(TwoFactorLocked::class, 1);
    }

    /** @return array<string, array{0: string}> */
    public static function tooShortSecrets(): array
    {
        return [
            'empty' => [''],
            'nine bytes' => [Totp::base32Encode(str_repeat('k', 9))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tooShortSecrets')]
    public function test_confirm_refuses_a_secret_shorter_than_80_bits(string $secret): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->twoFactor()->confirm($user, $secret, '123456');
        } finally {
            $this->assertNull($this->row($user)->two_factor_secret);
        }
    }

    public function test_confirm_accepts_an_80_bit_secret(): void
    {
        $secret = Totp::base32Encode(str_repeat('k', 10));

        $this->assertTrue($this->twoFactor()->confirm($this->user(), $secret, $this->codeAt($secret)));
    }

    public function test_malformed_input_while_confirming_counts_as_a_failure(): void
    {
        $user = $this->user();
        $secret = Totp::generateSecret();

        $this->assertFalse($this->twoFactor()->confirm($user, $secret, '12345'));
        $this->assertFalse($this->twoFactor()->confirm($user, $secret, 'abcdef'));
        $this->assertFalse($this->twoFactor()->confirm($user, $secret, ''));

        $this->expectException(TooManyAttempts::class);
        $this->twoFactor()->confirm($user, $secret, $this->codeAt($secret));
    }
}
