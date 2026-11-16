<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

class LaterCheck implements Check
{
    public function id(): string
    {
        return 'app_later';
    }

    public function label(): string
    {
        return '公開の時に要る調べ';
    }

    public function run(): Result
    {
        return Result::later('販売を始めるまで');
    }
}
