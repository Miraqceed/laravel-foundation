<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DataFiles\Sources;
use Miraqceed\Foundation\DataFiles\Store;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Throwable;

/**
 * 外のデータのファイル（foundation.data_files.sources）が置かれ、更新が止まっていないか。
 * 種類がなければ skip。置き場所にファイルがない → FAIL（写しに戻っているだけなら later）。
 * 最後に更新を流して成功してから staleAfterDays を過ぎた（まだ一度も成功していない）→ later（古くても読めるので止めない。--strict で失敗）。
 * 読むだけで、取りに行かない・書かない
 */
final class DataFiles implements Check
{
    /** 引数なしでも作れる（ほかの調べと同じく new で作る呼び方がある）。種類は run() のときに読む */
    public function __construct(private readonly ?Sources $sources = null) {}

    public function id(): string
    {
        return 'data_files';
    }

    public function label(): string
    {
        return '外のデータのファイルが置かれ、更新が止まっていない（php artisan foundation:data-files）';
    }

    public function run(): Result
    {
        try {
            $sources = ($this->sources ?? app(Sources::class))->all();
        } catch (Throwable $e) {
            return Result::fail('foundation.data_files.sources を読めない（'.$e::class.'）');
        }
        if ($sources === []) {
            return Result::skip('データのファイルの種類がない');
        }

        $failed = [];
        $later = [];
        $ok = [];
        foreach ($sources as $name => $source) {
            try {
                $store = Store::for($name);
                if (! $store->hasData()) {
                    $fallback = $source->fallbackPath();
                    if (is_string($fallback) && $fallback !== '' && is_file($fallback)) {
                        $later[] = "{$name}：置き場所になく、写しを読んでいる";
                    } else {
                        $failed[] = "{$name}：置き場所にない";
                    }

                    continue;
                }

                $days = $source->limits()->staleAfterDays;
                $checked = $store->checkedAt();
                if ($days !== null && $checked === null) {
                    $later[] = "{$name}：更新を流して成功したことがない";
                } elseif ($days !== null && $checked < now()->getTimestamp() - $days * 86400) {
                    $later[] = "{$name}：最後に成功した更新から{$days}日を過ぎた";
                } else {
                    $ok[] = $name;
                }
            } catch (Throwable $e) {
                $failed[] = "{$name}：調べられない（".$e::class.'）';
            }
        }

        $detail = implode('。', [...$failed, ...$later]);
        if ($failed !== []) {
            return Result::fail($detail.'。php artisan foundation:data-files で入れる');
        }
        if ($later !== []) {
            return Result::later($detail.'。php artisan foundation:data-files を schedule に入れ、流れているか確かめる');
        }

        return Result::ok(implode('・', $ok));
    }
}
