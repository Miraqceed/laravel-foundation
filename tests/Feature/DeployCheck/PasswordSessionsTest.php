<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Session\Middleware\AuthenticateSession;
use Miraqceed\Foundation\DeployCheck\Checks\PasswordSessions;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * password_sessions：セッションが database か、web のグループに auth.session があれば ok。どちらもなければ info
 */
class PasswordSessionsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'file');
    }

    private function checkStatus(): Status
    {
        return (new PasswordSessions)->run()->status;
    }

    public function test_セッションが_database_なら_ok(): void
    {
        config(['session.driver' => 'database']);

        $this->assertSame(Status::Ok, $this->checkStatus());
    }

    public function test_database_でなく_auth_session_もなければ_info(): void
    {
        $result = (new PasswordSessions)->run();

        $this->assertSame(Status::Info, $result->status);
        $this->assertStringContainsString('ほかの端末のセッションが残る', (string) $result->detail);
    }

    public function test_web_のグループに_auth_session_があれば_ok(): void
    {
        foreach (['auth.session', AuthenticateSession::class, AppAuthenticateSession::class] as $middleware) {
            $this->refreshApplication();
            config(['session.driver' => 'file']);
            app(HttpKernel::class)->appendMiddlewareToGroup('web', $middleware);

            $this->assertSame(Status::Ok, $this->checkStatus(), $middleware);
        }
    }

    public function test_web_でないグループの_auth_session_は数えない(): void
    {
        app(HttpKernel::class)->appendMiddlewareToGroup('api', 'auth.session');

        $this->assertSame(Status::Info, $this->checkStatus());
    }
}

/** アプリの AuthenticateSession の子（古い作り） */
class AppAuthenticateSession extends AuthenticateSession {}
