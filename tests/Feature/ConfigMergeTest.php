<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\TestCase;

class ConfigMergeTest extends TestCase
{
    /**
     * アプリが config/foundation.php を公開して、一部だけ書いた形にしてから、プロバイダーの登録をやり直す。
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['foundation' => [
            'login' => ['account_max' => 3],
            'test_safety' => ['markers' => ['staging']],
        ]]);
        (new FoundationServiceProvider($this->app))->register();
    }

    public function test_package_defaults_fill_missing_nested_keys(): void
    {
        $this->assertSame(3, config('foundation.login.account_max'));
        $this->assertSame(20, config('foundation.login.client_max'));
        $this->assertSame(60, config('foundation.login.decay_seconds'));
        $this->assertFalse(config('foundation.security.enabled'));
        $this->assertSame([], config('foundation.test_safety.ignore_connections'));
    }

    public function test_lists_are_replaced_not_merged_by_index(): void
    {
        $this->assertSame(['staging'], config('foundation.test_safety.markers'));
    }

    public function test_empty_array_keeps_defaults(): void
    {
        config(['foundation' => ['login' => [], 'security' => []]]);
        (new FoundationServiceProvider($this->app))->register();

        $this->assertSame(5, config('foundation.login.account_max'));
        $this->assertSame(20, config('foundation.login.client_max'));
        $this->assertFalse(config('foundation.security.enabled'));
    }
}
