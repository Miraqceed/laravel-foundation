<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Monitoring\WatchList;
use Miraqceed\Foundation\Tests\TestCase;

class WatchDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // external・heartbeat は触らない（既定のまま）
        $app['config']->set('cache.default', 'array');
        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
    }

    public function test_default_config_watches_no_external_task(): void
    {
        $this->assertSame([], config('foundation.monitoring.external'));
        $this->assertSame([], $this->app->make(WatchList::class)->all());
        $this->assertSame(0, Artisan::call('foundation:watch'));
    }
}
