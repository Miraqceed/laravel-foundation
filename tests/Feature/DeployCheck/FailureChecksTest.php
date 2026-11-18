<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Foundation\Application;
use Miraqceed\Foundation\DeployCheck\Checks\DbMaskedBindings;
use Miraqceed\Foundation\DeployCheck\Checks\ExceptionArgs;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * db_masked_bindings：既定の接続と挙げた接続の mask_bindings_in_exception_messages。13.27.0 より前は info
 * exception_args：CLI の zend.exception_ignore_args
 */
class FailureChecksTest extends TestCase
{
    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }

        parent::tearDown();
    }

    private static function supported(): bool
    {
        return version_compare(Application::VERSION, '13.27.0', '>=');
    }

    public function test_db_masked_bindings_は既定の接続を見る(): void
    {
        $default = (string) config('database.default');
        config(["database.connections.{$default}.mask_bindings_in_exception_messages" => false]);

        $result = (new DbMaskedBindings)->run();

        if (! self::supported()) {
            $this->assertSame(Status::Info, $result->status);
            $this->assertStringContainsString('13.27.0 から', (string) $result->detail);

            return;
        }

        $this->assertSame(Status::Later, $result->status);
        $this->assertStringContainsString("真でない接続：{$default}", (string) $result->detail);

        config(["database.connections.{$default}.mask_bindings_in_exception_messages" => true]);
        $this->assertSame(Status::Ok, (new DbMaskedBindings)->run()->status);
    }

    public function test_db_masked_bindings_は挙げた接続も見て_名前だけを出す(): void
    {
        $default = (string) config('database.default');
        config([
            "database.connections.{$default}.mask_bindings_in_exception_messages" => true,
            'database.connections.logs' => ['driver' => 'mysql', 'host' => 'db.internal.example', 'password' => 'secret-pass'],
            'foundation.deploy_check.connections' => ['logs', $default],
        ]);

        $result = (new DbMaskedBindings)->run();

        if (self::supported()) {
            $this->assertSame(Status::Later, $result->status);
            $this->assertStringContainsString('真でない接続：logs。', (string) $result->detail);
        }
        $this->assertStringNotContainsString('db.internal.example', (string) $result->detail);
        $this->assertStringNotContainsString('secret-pass', (string) $result->detail);
    }

    public function test_db_masked_bindings_はどの状態でも一意の違反の限界を添える(): void
    {
        $default = (string) config('database.default');
        config(["database.connections.{$default}.mask_bindings_in_exception_messages" => true]);

        $this->assertStringContainsString('一意の違反の文', (string) (new DbMaskedBindings)->run()->detail);
    }

    public function test_exception_args_は_On_なら_ok_Off_なら_later(): void
    {
        ini_set('zend.exception_ignore_args', '1');
        $on = (new ExceptionArgs)->run();
        $this->assertSame(Status::Ok, $on->status);
        $this->assertStringContainsString('CLI の値', (string) $on->detail);

        ini_set('zend.exception_ignore_args', '0');
        $off = (new ExceptionArgs)->run();
        $this->assertSame(Status::Later, $off->status);
        $this->assertStringContainsString('Web が別の php.ini', (string) $off->detail);
    }
}
