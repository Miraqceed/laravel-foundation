<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Ai\AiStore;

/**
 * AI の表を使う試しの RefreshDatabase。
 * RefreshDatabase は 1 プロセスで 1 回しか migrate:fresh しない。ほかのクラスが AI の表（や知らせの表）なしで
 * 作った後でも、要る表がなければ作り直す。
 */
trait RefreshesAiDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutAiTables;
    }

    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('foundation_ai_totals')
            || (config('foundation.monitoring.enabled') && ! Schema::hasTable('foundation_alerts'))) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutAiTables();
    }

    /** 別の接続の書き込みは RefreshDatabase で巻き戻らない。行だけを消す（DROP は MySQL で暗黙にコミットする） */
    protected function forgetSeparateAiRows(): void
    {
        if (AiStore::$separateInTests) {
            foreach (['foundation_ai_usages', 'foundation_ai_totals', 'foundation_ai_notices'] as $table) {
                DB::connection(AiStore::connectionName())->table($table)->delete();
            }
        }
        AiStore::$separateInTests = false;
    }
}
