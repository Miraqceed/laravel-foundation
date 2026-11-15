<?php

namespace Miraqceed\Foundation\Monitoring;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 定期の処理の実行の記録（foundation_task_runs）。
 *
 * 書き込みは savepoint で包み、失敗しても投げない（report() だけ）。外のトランザクションがあっても、
 * PostgreSQL の current transaction is aborted を起こさない。
 */
class TaskRuns
{
    public static function started(string $name): void
    {
        self::write($name, fn (string $now) => ['last_started_at' => $now]);
    }

    public static function finished(string $name, bool $success, ?string $detail = null): void
    {
        self::write($name, fn (string $now) => [
            $success ? 'last_succeeded_at' : 'last_failed_at' => $now,
            'last_result' => $detail,
        ]);
    }

    /** 見張りが初めて見た印。foundation:watch だけが呼ぶ。投げる（見張りが記録できないのは気付くべきこと） */
    public static function seen(string $name): void
    {
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($name, $now) {
            DB::table('foundation_task_runs')->insertOrIgnore(['name' => $name, 'first_seen_at' => $now, 'updated_at' => $now]);
            DB::table('foundation_task_runs')->where('name', $name)->whereNull('first_seen_at')
                ->update(['first_seen_at' => $now, 'updated_at' => $now]);
        });
    }

    /** @param  callable(string): array<string, mixed>  $columns */
    private static function write(string $name, callable $columns): void
    {
        if (! config('foundation.monitoring.enabled')) {
            return;
        }

        try {
            $now = now()->toDateTimeString();
            DB::transaction(function () use ($name, $columns, $now) {
                DB::table('foundation_task_runs')->updateOrInsert(
                    ['name' => $name],
                    $columns($now) + ['updated_at' => $now],
                );
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
