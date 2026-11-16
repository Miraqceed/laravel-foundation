<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class DuplicateIdCheck implements Check
{
    public function id(): string
    {
        return 'app_env';
    }

    public function label(): string
    {
        return '既定と同じ id の調べ';
    }

    public function run(): Result
    {
        return Result::ok();
    }
}
