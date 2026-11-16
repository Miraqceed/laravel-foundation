<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class MalformedIdCheck implements Check
{
    public function id(): string
    {
        return 'Bad-Id';
    }

    public function label(): string
    {
        return 'id の形が違う調べ';
    }

    public function run(): Result
    {
        return Result::ok();
    }
}
