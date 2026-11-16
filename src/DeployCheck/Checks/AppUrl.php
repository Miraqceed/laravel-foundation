<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

final class AppUrl implements Check
{
    public function id(): string
    {
        return 'app_url';
    }

    public function label(): string
    {
        return 'APP_URL が https の URL';
    }

    public function run(): Result
    {
        $url = config('app.url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return Result::fail('URL でない');
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? Result::ok() : Result::fail('https でない');
    }
}
