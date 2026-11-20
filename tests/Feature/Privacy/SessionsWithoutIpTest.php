<?php

namespace Miraqceed\Foundation\Tests\Feature\Privacy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Miraqceed\Foundation\Accounts\PasswordSessions;
use Miraqceed\Foundation\Privacy\DatabaseSessionHandlerWithoutIp;
use Miraqceed\Foundation\Tests\Support\AccountsUser;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * foundation.privacy.sessions_without_ip：database のセッションの表に訪問者の IP を書かない。無効なら Laravel のまま
 */
class SessionsWithoutIpTest extends TestCase
{
    private const VISITOR_IP = '203.0.113.7';

    private const USER_AGENT = 'FoundationTestBrowser/1.0';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', AccountsUser::class);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('foundation.privacy.sessions_without_ip', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/test/visit', function (Request $request) {
                $request->session()->put('seen', true);

                return 'ok';
            });

            $router->post('/test/login', function (Request $request) {
                Auth::login(AccountsUser::findOrFail($request->input('id')));

                return ['session' => $request->session()->getId()];
            });

            $router->post('/test/reset/{id}', function (string $id) {
                return ['ended' => app(PasswordSessions::class)->afterReset(AccountsUser::findOrFail($id))];
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
        });
        // Laravel の雛形の sessions の表と同じ形
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /** 要求ごとに別の端末にする（ガード・セッションを残さない） */
    private function newBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        // セッションの行の user_id は auth.driver（singleton）から読まれる。前の要求のガードを残さない
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
    }

    private function visit(string $ip = self::VISITOR_IP): string
    {
        $this->newBrowser();
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->get('/test/visit')
            ->assertOk();

        return $response->getCookie(config('session.cookie'))->getValue();
    }

    public function test_the_database_driver_keeps_its_name_and_uses_the_handler_without_ip(): void
    {
        $this->assertSame('database', config('session.driver'));
        $this->assertInstanceOf(DatabaseSessionHandlerWithoutIp::class, Session::driver('database')->getHandler());
    }

    public function test_a_visitor_who_is_not_signed_in_is_stored_without_ip(): void
    {
        $this->visit();

        $row = DB::table('sessions')->sole();
        $this->assertNull($row->ip_address);
        $this->assertSame(self::USER_AGENT, $row->user_agent);
        $this->assertNull($row->user_id);
    }

    public function test_a_signed_in_user_is_stored_with_the_user_id_and_without_ip(): void
    {
        $user = AccountsUser::create(['email' => 'alice@example.test', 'password' => Hash::make('password')]);

        $this->newBrowser();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::7'])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->postJson('/test/login', ['id' => $user->id])
            ->assertOk();

        $row = DB::table('sessions')->sole();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertNull($row->ip_address);
        $this->assertSame(self::USER_AGENT, $row->user_agent);
    }

    public function test_a_row_written_with_ip_before_is_cleared_on_the_next_request(): void
    {
        $this->visit();
        // 有効にする前に書いた行の形
        DB::table('sessions')->update(['ip_address' => self::VISITOR_IP]);
        $sessionId = DB::table('sessions')->value('id');

        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR_IP])
            ->withCookie(config('session.cookie'), $sessionId)
            ->get('/test/visit')
            ->assertOk();

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertNull(DB::table('sessions')->sole()->ip_address);
    }

    public function test_encrypted_sessions_are_stored_without_ip(): void
    {
        config(['session.encrypt' => true]);

        $this->visit();

        $row = DB::table('sessions')->sole();
        $this->assertNull($row->ip_address);
        $this->assertSame(self::USER_AGENT, $row->user_agent);
    }

    public function test_the_apps_own_extend_of_the_database_driver_wins(): void
    {
        // アプリのプロバイダーは、パッケージの後に起動する
        Session::extend('database', fn ($app) => new AppSessionHandler(
            $app['db']->connection(), 'sessions', 120, $app,
        ));
        $this->app['session']->forgetDrivers();

        $this->visit();

        $this->assertInstanceOf(AppSessionHandler::class, Session::driver('database')->getHandler());
        $this->assertSame('app-written', DB::table('sessions')->sole()->ip_address);
    }

    public function test_password_sessions_still_end_the_users_other_sessions(): void
    {
        $user = AccountsUser::create(['email' => 'alice@example.test', 'password' => Hash::make('password')]);

        foreach (['203.0.113.7', '198.51.100.7'] as $ip) {
            $this->newBrowser();
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/test/login', ['id' => $user->id])->assertOk();
        }
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->newBrowser();
        $this->postJson("/test/reset/{$user->id}")->assertOk()->assertJson(['ended' => 2]);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

}

/** アプリが自分で書いた database の handler（IP の代わりに決まった印を書く） */
class AppSessionHandler extends DatabaseSessionHandler
{
    protected function ipAddress(): ?string
    {
        return 'app-written';
    }
}
