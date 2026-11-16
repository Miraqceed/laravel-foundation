<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * 合言葉の表を使う試しの RefreshDatabase。
 * RefreshDatabase は 1 プロセスで 1 回しか migrate:fresh しない。ほかのクラスが合言葉の表なしで作った後でも、
 * 表がなければ作り直す（RefreshesAiDatabase と同じ形）
 */
trait RefreshesOneTimeCodeDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutOneTimeCodeTable;
    }

    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('foundation_one_time_codes')
            || (config('foundation.monitoring.enabled') && ! Schema::hasTable('foundation_alerts'))) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutOneTimeCodeTable();
    }

    /** クラスが終わったら移行の状態を戻す（次のクラスが、自分の要る表のない DB をそのまま使わないように） */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }
}
