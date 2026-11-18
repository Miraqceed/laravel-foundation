<?php

namespace Miraqceed\Foundation\Tests\Feature\Accounts;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Miraqceed\Foundation\Accounts\Fortify\EndSessionsAfterChange;
use Miraqceed\Foundation\Accounts\Fortify\EndSessionsAfterReset;
use Miraqceed\Foundation\Tests\Support\AccountsUser;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * Fortify の再設定（/reset-password）・パスワードの変更（/user/password）から、聞き手を登録したときだけ後始末が動く
 */
class FortifyPasswordSessionsTest extends TestCase
{
    private AccountsUser $alice;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FortifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', AccountsUser::class);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('fortify.views', false);
        $app['config']->set('fortify.features', [Features::resetPasswords(), Features::updatePasswords()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Fortify::resetUserPasswordsUsing(FortifyTestPasswordWriter::class);
        Fortify::updateUserPasswordsUsing(FortifyTestPasswordWriter::class);

        $this->alice = AccountsUser::create(['email' => 'alice@example.test', 'password' => Hash::make('old-password'), 'remember_token' => 'alice-old-token']);
        foreach (['alice-laptop', 'alice-phone'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->alice->id, 'payload' => '', 'last_activity' => time()]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /** @return list<string> */
    private function aliceSessions(): array
    {
        return DB::table('sessions')->where('user_id', $this->alice->id)->orderBy('id')->pluck('id')->all();
    }

    private function resetPassword(): void
    {
        $token = Password::broker()->createToken($this->alice);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'alice@example.test',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();
    }

    private function updatePassword(): void
    {
        $this->actingAs($this->alice)->putJson('/user/password', ['password' => 'new-password'])->assertOk();
    }

    public function test_再設定の聞き手を登録すると_すべてのセッションが消える(): void
    {
        Event::listen(PasswordReset::class, EndSessionsAfterReset::class);

        $this->resetPassword();

        $this->assertSame([], $this->aliceSessions());
        $this->assertTrue(Hash::check('new-password', $this->alice->fresh()->password));
    }

    public function test_変更の聞き手を登録すると_ほかのセッションが消え_この端末は入り直す(): void
    {
        Event::listen(PasswordUpdatedViaController::class, EndSessionsAfterChange::class);

        $this->updatePassword();

        // 残るのは、この要求の新しいセッションの行だけ
        $this->assertCount(1, $this->aliceSessions());
        $this->assertNotContains('alice-laptop', $this->aliceSessions());
        $this->assertNotContains('alice-phone', $this->aliceSessions());
        $this->assertNotSame('alice-old-token', $this->alice->fresh()->remember_token);
        $this->assertAuthenticatedAs($this->alice);
    }

    public function test_聞き手を登録しなければ_何も変わらない(): void
    {
        $this->resetPassword();
        $this->assertSame(['alice-laptop', 'alice-phone'], $this->aliceSessions());

        $this->updatePassword();
        $this->assertContains('alice-laptop', $this->aliceSessions());
        $this->assertContains('alice-phone', $this->aliceSessions());
    }
}

/** Fortify の雛形の ResetUserPassword・UpdateUserPassword の代わり（検証は省く） */
class FortifyTestPasswordWriter implements ResetsUserPasswords, UpdatesUserPasswords
{
    /** @param  array<string, string>  $input */
    public function reset($user, array $input): void
    {
        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }

    /** @param  array<string, string>  $input */
    public function update($user, array $input): void
    {
        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
