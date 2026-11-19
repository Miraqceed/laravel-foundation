<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * 知らせの印の表を使う試しの RefreshDatabase。
 * ほかのクラスが知らせの表なしで作った後でも、表がなければ作り直す（RefreshesOneTimeCodeDatabase と同じ形）
 */
trait RefreshesNoticeDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutNoticeTable;
    }

    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('foundation_notices')
            || (config('foundation.monitoring.enabled') && ! Schema::hasTable('foundation_alerts'))) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutNoticeTable();
    }

    /** クラスが終わったら移行の状態を戻す（次のクラスが、自分の要る表のない DB をそのまま使わないように） */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }
}
