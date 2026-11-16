<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * 書けないフォルダーがあると、ログ・キャッシュ・画面のコンパイルが落ちる。
 * 限界：このコマンドを流した利用者（CLI）で見る。Web のサーバーの利用者が違えば、そちらは見ない
 */
final class Writable implements Check
{
    public function id(): string
    {
        return 'writable';
    }

    public function label(): string
    {
        return 'storage と bootstrap/cache に書ける';
    }

    public function run(): Result
    {
        $dirs = [
            'storage/logs' => storage_path('logs'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'bootstrap/cache' => app()->bootstrapPath('cache'),
        ];

        $notWritable = array_keys(array_filter($dirs, fn (string $dir) => ! is_dir($dir) || ! is_writable($dir)));

        return $notWritable === [] ? Result::ok() : Result::fail('書けない: '.implode('・', $notWritable));
    }
}
