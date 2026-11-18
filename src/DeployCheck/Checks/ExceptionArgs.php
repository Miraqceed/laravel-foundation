<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * PHP の zend.exception_ignore_args が On か。Off だと、ログの積み跡に各段の文字列の引数（15字まで。IPv4 はそのまま収まる）が入る。
 * php.ini を読まない PHP と php.ini-development では Off。
 * 見えるのはコマンド（CLI）の値だけ。共用サーバーでは、Web が別の php.ini を読むことがある。
 */
final class ExceptionArgs implements Check
{
    private const WEB = 'CLI の値。Web が別の php.ini を読むなら、そちらも On にする';

    public function id(): string
    {
        return 'exception_args';
    }

    public function label(): string
    {
        return 'ログの積み跡に関数の引数を入れない（zend.exception_ignore_args）';
    }

    public function run(): Result
    {
        $value = ini_get('zend.exception_ignore_args');

        return filter_var($value, FILTER_VALIDATE_BOOL)
            ? Result::ok(self::WEB)
            : Result::later('Off。'.self::WEB);
    }
}
