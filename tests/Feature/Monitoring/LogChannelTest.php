<?php

namespace Miraqceed\Foundation\Tests\Feature\Monitoring;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Monitoring\Alerts\AlertDelivery;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Miraqceed\Foundation\Monitoring\Alerts\Channels\LogChannel;
use Miraqceed\Foundation\Tests\TestCase;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;

/**
 * log の送り先を、本物の Monolog の書式で通して確かめる（docs/monitoring.md「ログの1行の形」の v1 の契約）。
 */
class LogChannelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.monitoring.enabled', true);
        $app['config']->set('foundation.monitoring.alerts.channels', ['log']);
        $app['config']->set('app.name', 'TestApp');
        $app['config']->set('logging.default', 'probe_default');
        $app['config']->set('logging.channels.probe_default', $this->single('default'));
        // Laravel の既定の整形（空の context・extra を出さない）
        $app['config']->set('logging.channels.probe_laravel', $this->single('laravel'));
        // Monolog の素の整形（空の context・extra を「[] []」で出す）
        $app['config']->set('logging.channels.probe_monolog', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $this->file('monolog')],
            'formatter' => LineFormatter::class,
            'level' => 'debug',
        ]);
        // LOG_LEVEL が error より高いチャネル
        $app['config']->set('logging.channels.probe_critical', $this->single('critical', 'critical'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function file(string $name): string
    {
        $path = sys_get_temp_dir().'/foundation-log-'.$name.'-'.bin2hex(random_bytes(6)).'.log';
        $this->files[] = $path;

        return $path;
    }

    private function single(string $name, string $level = 'debug'): array
    {
        return ['driver' => 'single', 'path' => $this->file($name), 'level' => $level];
    }

    private function lines(string $channel): array
    {
        $path = config("logging.channels.{$channel}.path") ?? config("logging.channels.{$channel}.handler_with.stream");
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($path)), fn ($l) => $l !== ''));
    }

    /**
     * docs/monitoring.md の読み方をそのまま書いたもの：`foundation.alert ` の後ろから行末までを取り、
     * 末尾の空白と ` [] []`（Monolog の空の context と extra）を落として JSON として解析する。
     */
    private static function readLine(string $line): ?array
    {
        $at = strpos($line, 'foundation.alert ');
        if ($at === false) {
            return null;
        }
        $rest = rtrim(substr($line, $at + strlen('foundation.alert ')));
        if (str_ends_with($rest, ' [] []')) {
            $rest = rtrim(substr($rest, 0, -strlen(' [] []')));
        }

        return json_decode($rest, true, flags: JSON_THROW_ON_ERROR);
    }

    private function alert(string $body): Alert
    {
        return new Alert(['id' => 42, 'key' => 'k', 'subject' => '件名 {x}', 'body' => $body, 'created_at' => Carbon::parse('2026-11-15 10:00:00')]);
    }

    public function test_default_is_app_default_channel_at_error_level(): void
    {
        (new LogChannel)->deliver($this->alert('本文'));

        $lines = $this->lines('probe_default');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.ERROR: foundation.alert {', $lines[0]);
        $this->assertSame('本文', self::readLine($lines[0])['body']);
    }

    public function test_channel_and_level_follow_config(): void
    {
        config()->set('foundation.monitoring.alerts.log.channel', 'probe_laravel');
        config()->set('foundation.monitoring.alerts.log.level', 'critical');

        (new LogChannel)->deliver($this->alert('本文'));

        $this->assertSame([], $this->lines('probe_default'));
        $lines = $this->lines('probe_laravel');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.CRITICAL: foundation.alert {', $lines[0]);
    }

    public function test_line_is_readable_by_documented_rule_with_both_formatters(): void
    {
        // 本文に `}`・`] [`・`[] []`・改行を含めても、決めた読み方で元の値に戻る
        $body = "閉じ括弧 } と {\"a\":1} と [] [] を含む\n2行目 } [] []";

        foreach (['probe_laravel', 'probe_monolog'] as $channel) {
            config()->set('foundation.monitoring.alerts.log.channel', $channel);
            (new LogChannel)->deliver($this->alert($body));

            $lines = $this->lines($channel);
            $this->assertCount(1, $lines, $channel);
            $data = self::readLine($lines[0]);
            $this->assertSame(['v', 'id', 'key', 'subject', 'body', 'app', 'at'], array_keys($data), $channel);
            $this->assertSame(42, $data['id'], $channel);
            $this->assertSame($body, $data['body'], $channel);
            // {x} は Monolog の置き換え（PsrLogMessageProcessor）の対象にならない（context を渡さない）
            $this->assertSame('件名 {x}', $data['subject'], $channel);
        }

        // 素の整形では、空の context と extra が「[] []」で付く（パッケージは常に空で渡す）
        $this->assertStringEndsWith('} [] []', $this->lines('probe_monolog')[0]);
        // Laravel の既定の整形では付かない（行末は JSON の閉じ括弧と空白）
        $this->assertStringEndsWith('"}', rtrim($this->lines('probe_laravel')[0]));
    }

    public function test_level_below_channel_level_is_dropped_but_counts_as_sent(): void
    {
        // 説明書の注意：チャネルの level（LOG_LEVEL）より低いと捨てられ、送れた扱いになる
        config()->set('foundation.monitoring.alerts.log.channel', 'probe_critical');

        app(AlertSender::class)->send('k', 's', 'b');

        $this->assertSame([], $this->lines('probe_critical'));
        $this->assertNotNull(AlertDelivery::first()->sent_at);
    }

    public function test_invalid_utf8_is_substituted_and_still_delivered(): void
    {
        config()->set('foundation.monitoring.alerts.log.channel', 'probe_laravel');

        $alert = app(AlertSender::class)->send("k\xFF", "件名\xFF", "本文\xFF\xFEです");

        $this->assertNotNull($alert);
        $delivery = AlertDelivery::first();
        $this->assertNotNull($delivery->sent_at, (string) $delivery->last_error);
        $data = self::readLine($this->lines('probe_laravel')[0]);
        $this->assertTrue(mb_check_encoding($data['body'], 'UTF-8'));
        $this->assertStringStartsWith('本文', $data['body']);
        $this->assertStringEndsWith('です', $data['body']);
    }

    public function test_line_carries_the_stored_alert_id(): void
    {
        config()->set('foundation.monitoring.alerts.log.channel', 'probe_laravel');

        $first = app(AlertSender::class)->send('k1', '件名', '本文');
        $second = app(AlertSender::class)->send('k2', '件名', '本文');

        // 同じ鍵の新しい知らせと、同じ知らせの送り直しを、受け取る側が id で見分けられる
        $ids = array_map(fn ($line) => self::readLine($line)['id'], $this->lines('probe_laravel'));
        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertIsInt($ids[0]);
    }

    public function test_payload_does_not_throw_on_invalid_utf8(): void
    {
        $json = LogChannel::payload($this->alert("a\xFFb"));

        $this->assertTrue(mb_check_encoding($json, 'UTF-8'));
        $this->assertStringStartsWith('a', json_decode($json, true)['body']);
    }
}
