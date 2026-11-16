<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

final class AppDebug implements Check
{
    public function id(): string
    {
        return 'app_debug';
    }

    public function label(): string
    {
        return 'APP_DEBUG が偽（訪問者に例外の中身を見せない）';
    }

    public function run(): Result
    {
        return (bool) config('app.debug') ? Result::fail() : Result::ok();
    }
}
