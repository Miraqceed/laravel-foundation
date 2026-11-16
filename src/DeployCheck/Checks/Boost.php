<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * Laravel Boost は APP_DEBUG が真だと、どの環境でも口（ブラウザのログを受ける経路・画面への差し込み）を開く。
 * 本番には入れない（composer install --no-dev）か、BOOST_ENABLED=false にする
 */
final class Boost implements Check
{
    /** @param  string  $provider  Boost が入っているかを見るクラス（試しで差し替える） */
    public function __construct(private readonly string $provider = 'Laravel\Boost\BoostServiceProvider') {}

    public function id(): string
    {
        return 'boost';
    }

    public function label(): string
    {
        return 'Laravel Boost が切れている（BOOST_ENABLED=false か、入っていない）';
    }

    public function run(): Result
    {
        if (! class_exists($this->provider)) {
            return Result::skip('入っていない');
        }

        return config('boost.enabled') === false ? Result::ok() : Result::fail('入っていて、BOOST_ENABLED が false でない');
    }
}
