<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Session\DatabaseSessionHandler;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * database のセッションの表に、訪問者の IP を書くか（Laravel の DatabaseSessionHandler は、ログインしていない訪問者も含めて書く）。
 *
 * - セッションが database でなければ skip（IP を書く driver は database だけ）
 * - handler が ipAddress() を書き換えていれば ok（foundation.privacy.sessions_without_ip・アプリ自前の差し替え）
 * - Laravel のまま IP を書くなら info（IP を残してよいアプリもある。方針はアプリが決める）
 *
 * handler を作るだけで、DB に問い合わせない
 */
final class SessionIp implements Check
{
    public function id(): string
    {
        return 'session_ip';
    }

    public function label(): string
    {
        return 'database のセッションの表に訪問者の IP を書かない（決めたときだけ）';
    }

    public function run(): Result
    {
        if (config('session.driver') !== 'database') {
            return Result::skip('セッションが database でない');
        }

        $handler = app('session')->driver('database')->getHandler();

        if (! $handler instanceof DatabaseSessionHandler) {
            return Result::info('database の driver をアプリが別の handler に差し替えている（IP を書くかは見ない）');
        }

        if ((new \ReflectionMethod($handler, 'ipAddress'))->getDeclaringClass()->getName() === DatabaseSessionHandler::class) {
            return Result::info('sessions の表に訪問者の IP を書く（書かないなら foundation.privacy.sessions_without_ip）');
        }

        return Result::ok();
    }
}
