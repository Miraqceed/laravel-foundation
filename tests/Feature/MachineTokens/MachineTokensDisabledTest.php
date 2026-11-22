<?php

namespace Miraqceed\Foundation\Tests\Feature\MachineTokens;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Retention\ResolvedMap;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;

/** 入れただけでは変わらない：有効にしなければ、表のマイグレーションを読み込まず、台帳にも数えない */
class MachineTokensDisabledTest extends TestCase
{
    public function test_it_is_disabled_by_default(): void
    {
        $this->assertFalse(config('foundation.machine_tokens.enabled'));
    }

    public function test_migrations_are_not_loaded_unless_enabled(): void
    {
        // 表の有無は、ほかの試しが作った DB の残りで変わる。マイグレーションの置き場が登録されないことで確かめる
        $paths = array_filter(app('migrator')->paths(), fn ($path) => str_contains($path, 'machine-token-migrations'));

        $this->assertSame([], array_values($paths));
    }

    public function test_migrate_does_not_create_the_table_unless_enabled(): void
    {
        // ほかのクラスが作った表と記録を消してから流す（記録が残ると、読み込んでも流さないので確かめにならない）
        Schema::dropIfExists('foundation_machine_tokens');
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', 'like', '%create_foundation_machine_tokens%')->delete();
        }

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('foundation_machine_tokens'));
    }

    public function test_migrations_can_be_published(): void
    {
        $paths = ServiceProvider::pathsToPublish(FoundationServiceProvider::class, 'foundation-machine-token-migrations');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('machine-token-migrations', (string) array_key_first($paths));
        $this->assertDirectoryExists((string) array_key_first($paths));
    }

    public function test_the_data_map_does_not_count_the_table_unless_enabled(): void
    {
        $map = ResolvedMap::from(new ShopDataMap);

        // 有効でない部品（表があるかは、ほかの試しの残りで変わるので DB を見ない）
        $this->assertArrayNotHasKey('foundation_machine_tokens', $map->tables(lookAtDatabase: false));
        $this->assertSame('対象を消すとき、アプリが MachineTokens::revoke() を呼ぶ', $map->allTables()['foundation_machine_tokens']->handledBy());
    }

    /** migrate を流したので、次のクラスが今の DB をそのまま使わないように移行の状態を戻す */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }
}
