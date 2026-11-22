<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * 機械用のトークンの表を使う試しの RefreshDatabase。
 * RefreshDatabase は 1 プロセスで 1 回しか migrate:fresh しない。ほかのクラスがこの表なしで作った後でも、
 * 表がなければ作り直す（RefreshesOneTimeCodeDatabase と同じ形）
 */
trait RefreshesMachineTokenDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutMachineTokenTable;
    }

    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('foundation_machine_tokens')
            || (config('foundation.monitoring.enabled') && ! Schema::hasTable('foundation_alerts'))) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutMachineTokenTable();
    }

    /** クラスが終わったら移行の状態を戻す（次のクラスが、自分の要る表のない DB をそのまま使わないように） */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }
}
