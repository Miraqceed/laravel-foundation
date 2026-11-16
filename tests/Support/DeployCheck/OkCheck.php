<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class OkCheck implements Check
{
    public function id(): string
    {
        return 'app_ok';
    }

    public function label(): string
    {
        return '通る調べ';
    }

    public function run(): Result
    {
        return Result::ok();
    }
}
