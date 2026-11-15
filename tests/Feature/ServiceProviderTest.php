<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_provider_registers_config_with_parts_disabled(): void
    {
        $this->assertFalse(config('foundation.security.enabled'));
        $this->assertFalse(config('foundation.monitoring.enabled'));
        $this->assertSame(['test', 'testing'], config('foundation.test_safety.markers'));
        $this->assertSame([], config('foundation.test_safety.ignore_connections'));
        $this->assertIsArray(config('foundation.login'));

        // どのミドルウェアのグループにもパッケージのミドルウェアが入っていない
        $groups = $this->app['router']->getMiddlewareGroups();
        foreach ($groups as $middleware) {
            foreach ($middleware as $class) {
                $this->assertStringNotContainsString('Miraqceed\\Foundation', (string) $class);
            }
        }

        // schedule にパッケージの処理が入っていない
        $this->assertCount(0, $this->app->make(Schedule::class)->events());

        // マイグレーションは monitoring が有効なときだけ読み込む（置き場所の名前に頼らず実パスで比べる）
        $this->assertNotContains($this->packageMigrations(), $this->loadedMigrationPaths());
    }

    public function test_package_migrations_are_loaded_when_monitoring_is_enabled(): void
    {
        $this->app['config']->set('foundation.monitoring.enabled', true);
        (new \Miraqceed\Foundation\FoundationServiceProvider($this->app))->boot();

        $this->assertContains($this->packageMigrations(), $this->loadedMigrationPaths());
    }

    public function test_migrations_are_publishable_under_the_tag(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(
            \Miraqceed\Foundation\FoundationServiceProvider::class,
            'foundation-migrations',
        );

        $this->assertNotEmpty($paths);
        $this->assertContains(database_path('migrations'), array_values($paths));
    }

    private function packageMigrations(): string
    {
        return realpath(__DIR__.'/../../database/migrations');
    }

    /** @return list<string> */
    private function loadedMigrationPaths(): array
    {
        return array_map(fn ($path) => realpath($path) ?: $path, $this->app['migrator']->paths());
    }
}
