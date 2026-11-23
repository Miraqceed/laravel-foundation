<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Tests\Support\DeployCheck\DuplicateIdCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\FailingCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\LaterCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\MalformedIdCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\OkCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\ThrowingCheck;
use Miraqceed\Foundation\Tests\Support\DeployCheck\ThrowingConstructorCheck;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * foundation:deploy-check の走らせ方の決まり（1つの失敗で止めない・例外の文を出さない・終わりの値・JSON）
 */
class DeployCheckCommandTest extends TestCase
{
    /** 既定の調べの id と並び。CI の台本が読むので変えない */
    private const DEFAULT_IDS = [
        'app_env', 'app_debug', 'app_url', 'app_key', 'boost', 'session_secure_cookie', 'drivers', 'mail', 'mail_domain',
        'mail_view_cache', 'vite_hot', 'vite_manifest', 'writable', 'migrations', 'attempts_bypass', 'trusted_proxies',
        'trusted_hosts', 'mail_links', 'password_sessions', 'turnstile', 'db_masked_bindings', 'exception_args', 'queue_worker',
        'queue_timeouts', 'retention_schedule', 'postal_codes', 'data_files', 'integrity', 'session_ip', 'limiter_keys', 'ip_hash_key',
    ];

    /**
     * 既定の調べを外し、渡した調べだけを走らせる
     *
     * @param  list<mixed>  $checks
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function runOnly(array $checks, array $options = []): array
    {
        config(['foundation.deploy_check.skip' => self::DEFAULT_IDS, 'foundation.deploy_check.checks' => $checks]);

        $code = Artisan::call('foundation:deploy-check', $options);

        return [$code, Artisan::output()];
    }

    /** @return array<string, mixed> */
    private function decode(string $output): array
    {
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_既定の調べの_id_と並びは変わらない(): void
    {
        Artisan::call('foundation:deploy-check', ['--json' => true]);

        $ids = array_column($this->decode(Artisan::output())['checks'], 'id');
        $this->assertSame(self::DEFAULT_IDS, $ids);
    }

    public function test_投げた調べはクラス名だけで失敗し_残りは走る(): void
    {
        [$code, $output] = $this->runOnly([ThrowingCheck::class, OkCheck::class]);

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^\[FAIL\]\s+app_throws\s+投げる調べ: RuntimeException$/m', $output);
        $this->assertMatchesRegularExpression('/^\[ok\]\s+app_ok\s+通る調べ$/m', $output);
        $this->assertStringNotContainsString('db-user', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_作れない調べ_ないクラス_Check_でないクラスは_その行だけ失敗する(): void
    {
        [$code, $output] = $this->runOnly([ThrowingConstructorCheck::class, 'App\\DeployChecks\\Missing', \stdClass::class, 42, OkCheck::class]);

        $this->assertSame(1, $code);
        $this->assertSame(4, preg_match_all('/^\[FAIL\]\s+invalid_check\s/m', $output));
        $this->assertStringContainsString('ThrowingConstructorCheck: LogicException', $output);
        $this->assertStringContainsString('クラスが見つからない: App\\DeployChecks\\Missing', $output);
        $this->assertStringContainsString('Check を実装していない: stdClass', $output);
        $this->assertStringNotContainsString('db-user', $output);
        $this->assertMatchesRegularExpression('/^\[ok\]\s+app_ok\s/m', $output);
    }

    /**
     * アプリの調べのファイルに構文の誤りがあっても（自動読み込みで ParseError）、点検の全体は止まらない。
     * 壊れたファイルは試しの中で一時フォルダーに作る（tests の下に置くと、ほかの道具が読んで止まる）
     */
    public function test_構文の誤りのある調べのファイルは_その行だけ失敗する(): void
    {
        $dir = sys_get_temp_dir().'/foundation-deploy-check-broken-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/BrokenCheck.php', "<?php\nnamespace App\\DeployChecks;\nclass BrokenCheck implements \\Miraqceed\\Foundation\\DeployCheck\\Check {\n    public function id(): string { return 'broken' \n}\n");
        $loader = function (string $class) use ($dir): void {
            if ($class === 'App\\DeployChecks\\BrokenCheck') {
                require $dir.'/BrokenCheck.php';
            }
        };
        spl_autoload_register($loader);

        try {
            [$code, $output] = $this->runOnly(['App\\DeployChecks\\BrokenCheck', OkCheck::class]);
        } finally {
            spl_autoload_unregister($loader);
            @unlink($dir.'/BrokenCheck.php');
            @rmdir($dir);
        }

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^\[FAIL\]\s+invalid_check\s+調べを作れない: App\\\\DeployChecks\\\\BrokenCheck: ParseError$/m', $output);
        $this->assertMatchesRegularExpression('/^\[ok\]\s+app_ok\s/m', $output);
    }

    public function test_重なる_id_と形の違う_id_は失敗する(): void
    {
        config(['foundation.deploy_check.checks' => [DuplicateIdCheck::class, MalformedIdCheck::class]]);

        Artisan::call('foundation:deploy-check', ['--json' => true]);
        $rows = $this->decode(Artisan::output())['checks'];

        $appEnv = array_values(array_filter($rows, fn (array $row) => $row['id'] === 'app_env'));
        $this->assertCount(2, $appEnv);
        $this->assertSame('fail', $appEnv[1]['status']);
        $this->assertStringContainsString('id が重なる', $appEnv[1]['detail']);

        $invalid = array_values(array_filter($rows, fn (array $row) => $row['id'] === 'invalid_check'));
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('id の形が違う', $invalid[0]['detail']);
    }

    public function test_skip_に書いた_id_は走らせない(): void
    {
        config(['foundation.deploy_check.skip' => ['app_env']]);

        Artisan::call('foundation:deploy-check');

        $this->assertMatchesRegularExpression('/^\[skip\]\s+app_env\s+APP_ENV が production: foundation.deploy_check.skip で外した$/m', Artisan::output());
    }

    public function test_終わりの値は失敗で1_later_だけなら0_strict_なら1(): void
    {
        [$code, $output] = $this->runOnly([OkCheck::class, LaterCheck::class]);
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/^\[later\]\s+app_later\s+公開の時に要る調べ: 販売を始めるまで$/m', $output);
        $this->assertStringContainsString('設定の点検: 失敗 0', $output);

        [$code, $output] = $this->runOnly([OkCheck::class, LaterCheck::class], ['--strict' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('later 1（--strict では失敗とみなす）', $output);

        [$code] = $this->runOnly([OkCheck::class, FailingCheck::class]);
        $this->assertSame(1, $code);
    }

    public function test_json_の形(): void
    {
        [$code, $output] = $this->runOnly([OkCheck::class, LaterCheck::class, FailingCheck::class], ['--json' => true]);

        $this->assertSame(1, $code);
        $json = $this->decode($output);
        $this->assertSame(1, $json['schema_version']);
        $this->assertFalse($json['passed']);
        $byId = array_column($json['checks'], null, 'id');
        $this->assertSame(['id' => 'app_ok', 'status' => 'ok', 'label' => '通る調べ', 'detail' => null], $byId['app_ok']);
        $this->assertSame('later', $byId['app_later']['status']);
        $this->assertSame('fail', $byId['app_fail']['status']);
        $this->assertSame('skip', $byId['app_env']['status']);
    }

    public function test_checks_が配列でなければ1行の失敗(): void
    {
        [$code, $output] = $this->runOnly([]);
        $this->assertSame(0, $code);

        config(['foundation.deploy_check.skip' => self::DEFAULT_IDS, 'foundation.deploy_check.checks' => OkCheck::class]);
        $code = Artisan::call('foundation:deploy-check');

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^\[FAIL\]\s+invalid_check\s/m', Artisan::output());
    }
}
