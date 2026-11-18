<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * パスワードを変えた・再設定した後に、ほかの端末のセッションを終えられるか（Accounts\PasswordSessions が効く形か）。
 *
 * - セッションが database なら ok（行を消せる）
 * - web のグループに auth.session（AuthenticateSession、またはその子）があれば ok（次の要求でログアウトする）
 * - どちらもなければ info。部品を使っていないアプリにも当たるので、fail・later にしない。foundation.deploy_check.skip で外せる
 */
final class PasswordSessions implements Check
{
    public function id(): string
    {
        return 'password_sessions';
    }

    public function label(): string
    {
        return 'パスワードの変更・再設定の後に、ほかの端末のセッションを終えられる（セッションが database か、web のグループに auth.session）';
    }

    public function run(): Result
    {
        if (config('session.driver') === 'database') {
            return Result::ok('セッションが database');
        }

        if ($this->webGroupAuthenticatesSessions()) {
            return Result::ok('web のグループに auth.session');
        }

        return Result::info('セッションが database でなく、web のグループに auth.session もない：パスワードを変えても、ほかの端末のセッションが残る');
    }

    private function webGroupAuthenticatesSessions(): bool
    {
        // withMiddleware() の設定（authenticateSessions()・web(append:)）は、HTTP の Kernel を作るときに入る
        $kernel = app(HttpKernel::class);
        $groups = method_exists($kernel, 'getMiddlewareGroups') ? $kernel->getMiddlewareGroups() : [];
        $aliases = method_exists($kernel, 'getMiddlewareAliases') ? $kernel->getMiddlewareAliases() : [];

        foreach ($groups['web'] ?? [] as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // 'auth.session:引数' の形も、名前だけで見る
            $name = explode(':', $middleware, 2)[0];
            $class = $aliases[$name] ?? $name;

            if (is_string($class) && is_a($class, AuthenticatesSessions::class, true)) {
                return true;
            }
        }

        return false;
    }
}
