<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

final class SessionSecureCookie implements Check
{
    public function id(): string
    {
        return 'session_secure_cookie';
    }

    public function label(): string
    {
        return 'SESSION_SECURE_COOKIE が真（http の要求でログインのクッキーを送らない）';
    }

    public function run(): Result
    {
        return config('session.secure') === true ? Result::ok() : Result::fail();
    }
}
