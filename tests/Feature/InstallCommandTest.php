<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
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
