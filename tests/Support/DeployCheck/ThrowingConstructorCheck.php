<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class ThrowingConstructorCheck implements Check
{
    public function __construct()
    {
        throw new \LogicException('作るときに db-user@db-host を読んだ');
    }

    public function id(): string
    {
        return 'app_ctor';
    }

    public function label(): string
    {
        return '作れない調べ';
    }

    public function run(): Result
    {
        return Result::ok();
    }
}
