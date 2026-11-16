<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Commands\InstallCommand;
use Miraqceed\Foundation\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // 一時フォルダーは乱数の名前（並列でも衝突しない）
        $this->dir = sys_get_temp_dir().'/foundation-install-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.md') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function sections(string $text): int
    {
        return substr_count($text, '<!-- foundation:start -->');
    }

    public function test_install_writes_section_once(): void
    {
        file_put_contents($this->dir.'/CLAUDE.md', "# 自分の指針\n\n手書きの行\n");

        $this->assertSame(0, Artisan::call('foundation:install'));
        $this->assertSame(0, Artisan::call('foundation:install'));

        $text = file_get_contents($this->dir.'/CLAUDE.md');
        $this->assertSame(1, $this->sections($text));
        $this->assertSame(1, substr_count($text, '<!-- foundation:end -->'));
        $this->assertStringContainsString('手書きの行', $text);
        $this->assertStringContainsString('laravel-foundation', $text);
        $this->assertFileDoesNotExist($this->dir.'/AGENTS.md');
    }

    public function test_install_replaces_existing_section(): void
    {
        file_put_contents($this->dir.'/AGENTS.md', "前\n<!-- foundation:start -->\n古い内容\n<!-- foundation:end -->\n後\n");
        file_put_contents($this->dir.'/CLAUDE.md', "# c\n");

        Artisan::call('foundation:install');

        $agents = file_get_contents($this->dir.'/AGENTS.md');
        $this->assertStringNotContainsString('古い内容', $agents);
        $this->assertSame(1, $this->sections($agents));
        $this->assertStringStartsWith("前\n", $agents);
        $this->assertStringEndsWith("後\n", $agents);
        // あるものすべてに書く
        $this->assertSame(1, $this->sections(file_get_contents($this->dir.'/CLAUDE.md')));
    }

    public function test_install_creates_agents_md_when_none(): void
    {
        $this->assertSame(0, Artisan::call('foundation:install'));

        $this->assertFileExists($this->dir.'/AGENTS.md');
        $this->assertSame(1, $this->sections(file_get_contents($this->dir.'/AGENTS.md')));
        $this->assertFileDoesNotExist($this->dir.'/CLAUDE.md');
    }

    /**
     * 指針（Boost が読む）と foundation:install が書く節は同じ原本から作る。
     * どちらにも欠かせない語が入っていることを確かめる（欠けると、そのとおりに組んだアプリで守りが外れる）。
     */
    public function test_guideline_and_installed_section_share_required_words(): void
    {
        $guideline = (string) file_get_contents(InstallCommand::GUIDELINE);
        $section = InstallCommand::section();

        $required = [
            // テストの守りは入れた時点で効く。外への通信の遮断は任意（新しいアプリでは真）
            'test-safety.md', 'ignore_connections', '入れた時点で効く', 'prevent_stray_requests', 'Unknown database',
            // CSV
            'CsvResponse::download', 'Rows::keyset', 'CsvReader::parse',
            // 実在しないメールアドレス：判定・欄の規則・送る前の差し込み（既定は無効）。形の確かめではない
            'reserved-email-domains.md', 'ReservedEmailDomains::isReserved', 'NotReservedEmailDomain', 'drop_reserved_recipients', 'メールアドレスの形の確かめではない',
            // ログイン（3つとも要る。authenticateUsing が抜けると回数の制限が消える）
            'limiters', 'authenticateThrough', 'authenticateUsing', 'trustProxies',
            // CSP（web のグループでなくグローバルの先頭。web のグループでは 404・503・413 に付かない）
            'foundation.security.enabled', 'foundation.security-headers', 'Nonce::get', 'Vite::cspNonce()', 'report_route', 'frame-ancestors',
            '$middleware->prepend(\\Miraqceed\\Foundation\\Security\\SecurityHeaders::class)', 'withoutMiddleware()',
            // 見張り
            'foundation.monitoring.enabled', 'migrate', 'watched', 'foundation:record', 'monitoring.alerts.log.channel', 'stderr',
            'environments()', 'foundation.watch:', 'throttle_minutes - 5',
            // 知らせの表は model:prune で消す（アプリが schedule に入れる）
            'model:prune', '--model', 'prune_days',
            // database のキャッシュの期限切れの行は foundation:cache-prune で消す（アプリが schedule に入れる）
            'foundation:cache-prune',
            // AI の費用の上限
            'foundation.ai.enabled', 'Laravel AI SDK', 'AiLimitReached', 'when_uncountable', 'Miraqceed\\Foundation\\Ai\\AiUsage',
            // 利用者の URL をサーバーから開く（守りは別のパッケージ。Guzzle は直った版から。PHP 8.3 では作らずに知らせる）
            'ssrf.md', 'cboxdk/laravel-ssrf', 'Http::ssrf()', 'PublicUrl', '7.15.2', 'HTTP_PROXY', 'assertSafeRedirect',
            'PHP 8.3 のアプリでは',
        ];
        foreach ($required as $word) {
            $this->assertStringContainsString($word, $guideline, "指針に {$word} がない");
            $this->assertStringContainsString($word, $section, "install の節に {$word} がない");
        }
    }

    public function test_installed_section_is_plain_markdown(): void
    {
        $section = InstallCommand::section();

        // Blade・Boost の書き方は残さない
        foreach (['@verbatim', '@endverbatim', '<code-snippet', '</code-snippet>', '@{{'] as $blade) {
            $this->assertStringNotContainsString($blade, $section);
        }
        // 日本語の文字を割らない（\R を /u なしで使うと \x85 で切れる）
        $this->assertTrue(mb_check_encoding($section, 'UTF-8'));
        $this->assertStringContainsString("```php\n", $section);
        $this->assertSame(substr_count($section, '```') % 2, 0);
    }

    public function test_install_works_with_parts_disabled(): void
    {
        config([
            'foundation.security.enabled' => false,
            'foundation.monitoring.enabled' => false,
        ]);

        $this->assertSame(0, Artisan::call('foundation:install'));
        $this->assertFileExists($this->dir.'/AGENTS.md');
    }
}
