<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * キューの表（jobs・failed_jobs）と知らせの表を使う試しの RefreshDatabase。
 * RefreshDatabase は 1 プロセスで 1 回しか migrate:fresh しないので、ほかのクラスがキューの表なしで作った後でも作り直す
 */
trait RefreshesQueueDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as refreshWithoutQueueTables;
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/queue-migrations');
    }

    protected function refreshTestDatabase(): void
    {
        if (! Schema::hasTable('jobs') || (config('foundation.monitoring.enabled') && ! Schema::hasTable('foundation_alerts'))) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->refreshWithoutQueueTables();
    }

    /** 状態を残すと、普通の RefreshDatabase を使う次のクラスが、キューの表のある DB をそのまま使う（害はないが、作り直しの判断をそろえる） */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }
}
