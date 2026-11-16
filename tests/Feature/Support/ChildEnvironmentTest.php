<?php

namespace Miraqceed\Foundation\Tests\Feature\Support;

use Illuminate\Support\Facades\Process;
use Miraqceed\Foundation\Support\ChildEnvironment;
use Miraqceed\Foundation\Tests\TestCase;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * 子プロセスに親の環境変数（.env の秘密）を受け継がせない。
 * Symfony Process（と、それを使う Laravel の Process）は、渡した環境に親の環境（$_ENV と $_SERVER と名前が重なる getenv()）を足して子に渡す。
 * 秘密はすべて作り物
 */
class ChildEnvironmentTest extends TestCase
{
    /** 作り物の秘密を置く場所と名前 */
    private const SECRETS = [
        'env' => 'KASOU_ENV_SECRET',
        'server' => 'KASOU_SERVER_SECRET',
        'both' => 'KASOU_DB_PASSWORD',
        'putenv' => 'KASOU_PUTENV_SECRET',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh が要る');
        }
        $_ENV[self::SECRETS['env']] = 'fake-env-secret';
        $_SERVER[self::SECRETS['server']] = 'fake-server-secret';
        // Laravel が .env を読むと、値は $_ENV と $_SERVER の両方に入る
        $_ENV[self::SECRETS['both']] = 'fake-db-password';
        $_SERVER[self::SECRETS['both']] = 'fake-db-password';
        putenv(self::SECRETS['putenv'].'=fake-putenv-secret');
        $_SERVER[self::SECRETS['putenv']] = 'fake-putenv-secret';
    }

    protected function tearDown(): void
    {
        foreach (self::SECRETS as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        unset($_ENV['KASOU_PASS_THROUGH'], $_SERVER['KASOU_PASS_THROUGH']);

        parent::tearDown();
    }

    /** 子が見た作り物の値（見えなければ none） */
    private static function script(): string
    {
        $parts = array_map(fn (string $name) => "{$name}=\${{$name}:-none}", [...array_values(self::SECRETS), 'KASOU_PASS_THROUGH', 'KASOU_SET']);

        return 'echo "'.implode(' ', $parts).'"';
    }

    private function assertNoSecretSeen(string $output): void
    {
        foreach (['fake-env-secret', 'fake-server-secret', 'fake-db-password', 'fake-putenv-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_child_does_not_see_parent_secrets_with_laravel_process(): void
    {
        $output = Process::env(ChildEnvironment::only(['PATH']))->run(['/bin/sh', '-c', self::script()])->output();

        $this->assertStringContainsString('KASOU_DB_PASSWORD=none', $output);
        $this->assertNoSecretSeen($output);
    }

    public function test_child_does_not_see_parent_secrets_with_symfony_process(): void
    {
        $process = new SymfonyProcess(['/bin/sh', '-c', self::script()], null, ChildEnvironment::only(['PATH']));
        $process->run();

        $this->assertStringContainsString('KASOU_DB_PASSWORD=none', $process->getOutput());
        $this->assertNoSecretSeen($process->getOutput());
    }

    public function test_listed_names_and_set_values_reach_the_child(): void
    {
        $_ENV['KASOU_PASS_THROUGH'] = 'passed';
        $_SERVER['KASOU_PASS_THROUGH'] = 'passed';

        $output = Process::env(ChildEnvironment::only(['PATH', 'KASOU_PASS_THROUGH'], ['KASOU_SET' => 'set-value']))
            ->run(['/bin/sh', '-c', self::script()])->output();

        $this->assertStringContainsString('KASOU_PASS_THROUGH=passed', $output);
        $this->assertStringContainsString('KASOU_SET=set-value', $output);
        $this->assertNoSecretSeen($output);
    }

    /** 決めて渡す値（$set）は、親から渡す値にも、塞いだ親の名前にも勝つ */
    public function test_set_values_override_listed_and_blocked_names(): void
    {
        $_ENV['KASOU_PASS_THROUGH'] = 'passed';
        $_SERVER['KASOU_PASS_THROUGH'] = 'passed';

        $environment = ChildEnvironment::only(['PATH', 'KASOU_PASS_THROUGH'], [
            'KASOU_PASS_THROUGH' => 'overridden',
            'KASOU_DB_PASSWORD' => 'explicit-value',
        ]);
        $output = Process::env($environment)->run(['/bin/sh', '-c', self::script()])->output();

        $this->assertStringContainsString('KASOU_PASS_THROUGH=overridden', $output);
        $this->assertStringContainsString('KASOU_DB_PASSWORD=explicit-value', $output);
        $this->assertStringNotContainsString('fake-db-password', $output);
    }

    public function test_a_listed_name_missing_from_the_parent_is_not_added(): void
    {
        $environment = ChildEnvironment::only(['PATH', 'KASOU_NOT_IN_PARENT']);

        $this->assertArrayNotHasKey('KASOU_NOT_IN_PARENT', $environment);
        $output = Process::env($environment)->run(['/bin/sh', '-c', 'echo "${KASOU_NOT_IN_PARENT+set}"'])->output();
        $this->assertSame('', trim($output));
    }

    public function test_only_false_or_listed_values_are_in_the_array(): void
    {
        $environment = ChildEnvironment::only(['PATH']);

        foreach ($environment as $name => $value) {
            $this->assertTrue($value === false || $name === 'PATH', (string) $name);
        }
        $this->assertFalse($environment['KASOU_DB_PASSWORD']);
    }

    /**
     * 守りが要ることの記録：何も渡さないと、子は親の .env の値（$_ENV・$_SERVER）を受け継ぐ。
     * Symfony Process の振る舞いが替わって受け継がなくなったら、飛ばす（CI を落とさない）
     */
    public function test_without_the_helper_the_child_sees_parent_secrets(): void
    {
        $output = Process::run(['/bin/sh', '-c', self::script()])->output();

        if (! str_contains($output, 'fake-db-password')) {
            $this->markTestSkipped('この版の Process は、親の環境を子に受け継がなかった');
        }
        $this->assertStringContainsString('KASOU_DB_PASSWORD=fake-db-password', $output);
    }
}
