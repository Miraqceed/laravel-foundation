<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * キャッシュ・セッションが array（要求をまたいで何も残らない）でないか。種類の名前だけを出す
 */
final class Drivers implements Check
{
    public function id(): string
    {
        return 'drivers';
    }

    public function label(): string
    {
        return 'キャッシュ・セッションが array でない';
    }

    public function run(): Result
    {
        $cacheStore = config('cache.default');
        $cache = is_string($cacheStore) ? config("cache.stores.{$cacheStore}.driver") : null;
        $session = config('session.driver');
        $queue = config('queue.default');
        $queueDriver = is_string($queue) ? config("queue.connections.{$queue}.driver") : null;

        $names = 'キャッシュ '.self::name($cache).'・セッション '.self::name($session).'・キュー '.self::name($queueDriver);

        if ($cache === 'array' || $session === 'array') {
            return Result::fail($names);
        }

        return Result::ok($names);
    }

    private static function name(mixed $driver): string
    {
        // 種類の名前（英字）だけを出す。それ以外の形は値を出さない
        return is_string($driver) && preg_match('/\A[a-z0-9_-]{1,32}\z/i', $driver) === 1 ? $driver : '（不明）';
    }
}
