<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Encryption\Encrypter;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * 鍵が暗号の方式（app.cipher）に合う長さか。入っているだけでは足りない（長さが違うと、暗号を使う所で初めて落ちる）
 */
final class AppKey implements Check
{
    public function id(): string
    {
        return 'app_key';
    }

    public function label(): string
    {
        return 'APP_KEY（と APP_PREVIOUS_KEYS）が暗号の方式に合う鍵';
    }

    public function run(): Result
    {
        $cipher = config('app.cipher');
        if (! is_string($cipher)) {
            return Result::fail('app.cipher が文字でない');
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return Result::fail('APP_KEY が空');
        }
        if (! self::supported($key, $cipher)) {
            return Result::fail("APP_KEY が {$cipher} に合わない（php artisan key:generate --show で作り直す）");
        }

        $previous = config('app.previous_keys', []);
        foreach (is_array($previous) ? array_values($previous) : [$previous] as $i => $old) {
            if (! is_string($old) || ! self::supported($old, $cipher)) {
                return Result::fail('APP_PREVIOUS_KEYS の '.($i + 1)." 番目が {$cipher} に合わない");
            }
        }

        return Result::ok();
    }

    private static function supported(string $key, string $cipher): bool
    {
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && Encrypter::supported($key, $cipher);
    }
}
