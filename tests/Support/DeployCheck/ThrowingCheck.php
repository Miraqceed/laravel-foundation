<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class ThrowingCheck implements Check
{
    public function id(): string
    {
        return 'app_throws';
    }

    public function label(): string
    {
        return '投げる調べ';
    }

    public function run(): Result
    {
        throw new \RuntimeException('SQLSTATE[HY000] db-user@db-host:3306 につながらない');
    }
}
