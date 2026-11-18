<?php

namespace Miraqceed\Foundation\Failures;

use Throwable;

/**
 * 記録の間引き。鍵ごとに、窓の中で1回だけ記録させる（PHP のプロセスをまたいで、storage の目印のファイルで数える）。
 *
 * わざとキャッシュ・DB を使わない。間引きたいのは DB が落ちているときで、本番のキャッシュが database だと、
 * 間引きも失敗して何も記録されず、記録のたびに DB への接続を余分に試みる。
 * 目印を読めない・書けないときは、記録する側に倒す（間引きの失敗で記録を隠さない）。
 */
final class ReportThrottle
{
    public function shouldReport(string $key, int $windowSeconds): bool
    {
        try {
            $directory = self::directory();
            // 鍵に何が入っても、ファイルの名前に出さない
            $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', $key);
            $now = now()->getTimestamp();

            clearstatcache(true, $path);
            $reportedAt = @filemtime($path);
            // 時刻が戻った（目印が先の時刻）ときは、古い目印とみなして記録する
            if ($reportedAt !== false && $reportedAt <= $now && $now - $reportedAt < $windowSeconds) {
                return false;
            }

            if (is_dir($directory) || @mkdir($directory, 0700, true)) {
                @touch($path, $now);
            }
        } catch (Throwable) {
            // 判定の失敗では記録を止めない
        }

        return true;
    }

    private static function directory(): string
    {
        $path = config('foundation.failures.throttle_path');

        return is_string($path) && $path !== ''
            ? rtrim($path, '/\\')
            : storage_path('framework/foundation/report-throttle');
    }
}
