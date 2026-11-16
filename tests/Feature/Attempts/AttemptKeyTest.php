<?php

namespace Miraqceed\Foundation\Tests\Feature\Attempts;

use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 数えの鍵は APP_KEY の HMAC。キャッシュの表の鍵から IP・メールアドレスを総当たりで戻せないように
 */
class AttemptKeyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_key_depends_on_app_key(): void
    {
        $before = AttemptKey::for('login', 'a@example.test', '203.0.113.5');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32))]);

        $this->assertNotSame($before, AttemptKey::for('login', 'a@example.test', '203.0.113.5'));
    }

    public function test_same_parts_give_the_same_key(): void
    {
        $this->assertSame(AttemptKey::for('login', 'a@example.test'), AttemptKey::for('login', 'a@example.test'));
    }

    public function test_key_does_not_contain_the_parts(): void
    {
        $key = AttemptKey::for('login', 'a@example.test', '203.0.113.5');

        $this->assertStringNotContainsString('a@example.test', $key);
        $this->assertStringNotContainsString('203.0.113.5', $key);
        $this->assertStringStartsWith('foundation-attempts:login:', $key);
    }

    public function test_parts_are_separated(): void
    {
        $this->assertNotSame(AttemptKey::for('x', 'ab', 'c'), AttemptKey::for('x', 'a', 'bc'));
        $this->assertNotSame(AttemptKey::for('x', 'a'), AttemptKey::for('y', 'a'));
    }

    public function test_empty_app_key_is_refused(): void
    {
        config(['app.key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');

        AttemptKey::for('login', 'a@example.test');
    }

    public function test_package_defaults_fill_attempts_config(): void
    {
        config(['foundation' => ['attempts' => ['consecutive' => ['max' => 3]]]]);
        (new FoundationServiceProvider($this->app))->register();

        $this->assertFalse(config('foundation.attempts.bypass'));
        $this->assertSame(3, config('foundation.attempts.consecutive.max'));
        $this->assertSame(1800, config('foundation.attempts.consecutive.lock_seconds'));
    }
}
