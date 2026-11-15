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

        // マイグレーションは monitoring が有効なときだけ読み込む
        foreach ($this->app['migrator']->paths() as $path) {
            $this->assertStringNotContainsString('laravel-foundation', $path);
            $this->assertStringNotContainsString('miraq-foundation', $path);
        }
    }
}
