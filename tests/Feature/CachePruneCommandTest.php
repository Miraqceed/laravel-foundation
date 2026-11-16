<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * database のキャッシュの期限切れの行を消す。表は試しの中で作って消す
 * （RefreshDatabase のトランザクションの中で表を作ると、MySQL では暗黙にコミットされて後の試しが壊れるため）。
 */
class CachePruneCommandTest extends TestCase
{
    private const NOW = '2026-11-16 10:00:00';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        foreach (['cache', 'other_cache'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }
        Schema::dropIfExists('cache_locks');
        Schema::create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('other_cache');
        Schema::dropIfExists('cache_locks');
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, int>  $rows  鍵 => 今からの秒（負なら期限切れ） */
    private function store(array $rows, string $table = 'cache'): void
    {
        $now = Carbon::now()->getTimestamp();
        foreach (array_chunk($rows, 500, true) as $chunk) {
            DB::table($table)->insert(array_map(
                fn (string $key, int $seconds) => ['key' => $key, 'value' => 'x', 'expiration' => $now + $seconds],
                array_keys($chunk),
                $chunk,
            ));
        }
    }

    /** @return list<string> */
    private function keys(string $table = 'cache'): array
    {
        return DB::table($table)->orderBy('key')->pluck('key')->all();
    }

    public function test_期限切れの行だけを消す(): void
    {
        $this->store(['expired' => -60, 'just-expired' => 0, 'fresh' => 60, 'forever' => 315360000]);

        $this->assertSame(0, Artisan::call('foundation:cache-prune'));

        $this->assertSame(['forever', 'fresh'], $this->keys());
        $this->assertStringContainsString('2', Artisan::output());
    }

    public function test_千件を超えても残さず消す(): void
    {
        $rows = [];
        foreach (range(1, 2500) as $i) {
            $rows["login:198.51.100.{$i}"] = -60;
        }
        $rows['fresh'] = 60;
        $this->store($rows);

        $this->assertSame(0, Artisan::call('foundation:cache-prune'));

        $this->assertSame(['fresh'], $this->keys());
    }

    public function test_読んだ後に書き直された行は消さない(): void
    {
        $this->store(['rewritten' => -60, 'expired' => -60]);

        // 期限切れの鍵を読んだ直後（消す前）に、アプリが同じ鍵を書き直す
        $rewritten = false;
        DB::listen(function (QueryExecuted $query) use (&$rewritten) {
            if (! $rewritten && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'cache')) {
                $rewritten = true;
                DB::table('cache')->where('key', 'rewritten')->update(['expiration' => Carbon::now()->getTimestamp() + 3600]);
            }
        });

        $this->assertSame(0, Artisan::call('foundation:cache-prune'));

        $this->assertTrue($rewritten);
        $this->assertSame(['rewritten'], $this->keys());
    }

    public function test_database_のストアでなければ何もしない(): void
    {
        config(['cache.default' => 'array']);
        $this->store(['expired' => -60]);

        $this->assertSame(0, Artisan::call('foundation:cache-prune'));

        $this->assertSame(['expired'], $this->keys());
        $this->assertStringContainsString('database', Artisan::output());
    }

    public function test_ストアを選べて、その表を消す(): void
    {
        config([
            'cache.default' => 'array',
            'cache.stores.second' => ['driver' => 'database', 'table' => 'other_cache', 'connection' => null],
        ]);
        $this->store(['expired' => -60]);
        $this->store(['expired' => -60, 'fresh' => 60], 'other_cache');

        $this->assertSame(0, Artisan::call('foundation:cache-prune', ['--store' => 'second']));

        $this->assertSame(['fresh'], $this->keys('other_cache'));
        $this->assertSame(['expired'], $this->keys());
    }

    public function test_ロックの表には触らない(): void
    {
        $now = Carbon::now()->getTimestamp();
        DB::table('cache_locks')->insert(['key' => 'lock', 'owner' => 'o', 'expiration' => $now - 60]);

        Artisan::call('foundation:cache-prune');

        $this->assertSame(1, DB::table('cache_locks')->count());
    }
}
