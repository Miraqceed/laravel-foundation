<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

final class AppEnv implements Check
{
    public function id(): string
    {
        return 'app_env';
    }

    public function label(): string
    {
        return 'APP_ENV が production';
    }

    public function run(): Result
    {
        return config('app.env') === 'production' ? Result::ok() : Result::fail();
    }
}
