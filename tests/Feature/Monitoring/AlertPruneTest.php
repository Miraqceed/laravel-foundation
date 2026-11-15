<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\AlertDelivery;
use Miraqceed\Foundation\Tests\TestCase;

/** 知らせの表は model:prune で消す（送れたか諦めて prune_days 日たった知らせ） */
class AlertPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<array{sent?: int, abandoned?: int}>  $deliveries  送れた・諦めた日（何日前か）
     */
    private function alert(string $key, int $createdDaysAgo, array $deliveries): Alert
    {
        $alert = Alert::create(['key' => $key, 'subject' => 's', 'body' => 'b', 'created_at' => now()->subDays($createdDaysAgo)]);
        foreach ($deliveries as $d) {
            AlertDelivery::create([
                'alert_id' => $alert->id,
                'channel' => 'log',
                'attempts' => 1,
                'sent_at' => isset($d['sent']) ? now()->subDays($d['sent']) : null,
                'abandoned_at' => isset($d['abandoned']) ? now()->subDays($d['abandoned']) : null,
            ]);
        }

        return $alert;
    }

    private function prune(): void
    {
        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [Alert::class]]));
    }

    /** @return list<string> */
    private function keys(): array
    {
        return Alert::orderBy('key')->pluck('key')->all();
    }

    public function test_settled_alerts_older_than_90_days_are_pruned_with_their_deliveries(): void
    {
        $this->alert('sent-old', 100, [['sent' => 100]]);
        $this->alert('abandoned-old', 100, [['abandoned' => 95]]);
        $this->alert('both-old', 120, [['sent' => 119], ['abandoned' => 91]]);
        $this->alert('no-delivery-old', 100, []);
        $this->alert('abandoned-recently', 100, [['abandoned' => 10]]);
        $this->alert('sent-recently', 100, [['sent' => 89]]);
        $this->alert('pending', 100, [[]]);
        $this->alert('one-pending', 100, [['sent' => 100], []]);
        $this->alert('new', 10, [['sent' => 10]]);

        $this->prune();

        $this->assertSame(['abandoned-recently', 'new', 'one-pending', 'pending', 'sent-recently'], $this->keys());
        // 配送の行は知らせと一緒に消える（外部キーの cascade）
        $this->assertSame(0, AlertDelivery::whereNotIn('alert_id', Alert::pluck('id'))->count());
        $this->assertSame(6, AlertDelivery::count());
    }

    public function test_prune_days_follow_config(): void
    {
        config()->set('foundation.monitoring.alerts.prune_days', 30);
        $this->alert('sent-40', 40, [['sent' => 40]]);
        $this->alert('sent-20', 40, [['sent' => 20]]);

        $this->prune();

        $this->assertSame(['sent-20'], $this->keys());
    }

    public function test_default_prune_days_is_90(): void
    {
        $this->assertSame(90, config('foundation.monitoring.alerts.prune_days'));
    }
}
