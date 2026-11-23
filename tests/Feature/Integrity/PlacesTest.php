<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Integrity\Checks\MaintenanceCheck;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 調べ3（メンテナンス）・4（公開される変わる場所）・6（storage の PHP）
 */
class PlacesTest extends TestCase
{
    use IntegrityTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
        $this->snapshot();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    public function test_storage_link_targets_are_looked_at_by_name_and_kind(): void
    {
        config(['filesystems.links' => [$this->root.'/public/storage' => $this->root.'/storage/app/public']]);
        $this->writeFile('storage/app/public/photo.png', 'x');
        $this->writeFile('storage/app/public/x.php', '<?php');
        $this->writeFile('storage/app/public/.htaccess', 'AddHandler x');
        $this->writeFile('storage/app/public/.user.ini', 'auto_prepend_file=x');
        symlink($this->root.'/.env', $this->root.'/storage/app/public/env');
        link($this->root.'/.env', $this->root.'/storage/app/public/env-hard');
        posix_mkfifo($this->root.'/storage/app/public/pipe', 0644);

        $report = $this->check();

        $this->assertFinding($report, Finding::ServedPhp, 'storage/app/public/x.php');
        $this->assertFinding($report, Finding::ServedConfig, 'storage/app/public/.htaccess');
        $this->assertFinding($report, Finding::ServedConfig, 'storage/app/public/.user.ini');
        $this->assertFinding($report, Finding::ServedLink, 'storage/app/public/env');
        $this->assertFinding($report, Finding::ServedHardLink, 'storage/app/public/env-hard');
        $this->assertFinding($report, Finding::NotRegular, 'storage/app/public/pipe');
        $this->assertSame([], array_filter($this->findings($report), fn ($f) => str_contains($f, 'photo.png')));
    }

    public function test_a_link_target_outside_the_tree_is_named_without_the_absolute_path(): void
    {
        $outside = sys_get_temp_dir().'/foundation-integrity-outside-'.bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside.'/x.php', '<?php');
        try {
            config(['filesystems.links' => [$this->root.'/public/files' => $outside]]);
            $report = $this->check();

            $this->assertFinding($report, Finding::ServedPhp, 'filesystems.links の先/x.php');
            $this->assertStringNotContainsString($outside, implode("\n", $this->findings($report)));
        } finally {
            unlink($outside.'/x.php');
            rmdir($outside);
        }
    }

    public function test_acme_folder_passes_tokens_and_finds_anything_else(): void
    {
        $base = 'public/.well-known/acme-challenge';
        $this->writeFile($base.'/AbC-123_x', 'token');
        $this->writeFile($base.'/page.html', 'phish');
        $this->writeFile($base.'/.htaccess', 'x');
        $this->writeFile($base.'/sub/token', 'x');
        symlink($this->root.'/.env', $this->root.'/'.$base.'/linked');
        link($this->root.'/.env', $this->root.'/'.$base.'/hardlinked');

        $report = $this->check();

        $this->assertNoFinding($report, Finding::AcmeFile, $base.'/AbC-123_x');
        foreach (['page.html', '.htaccess', 'sub/token', 'linked', 'hardlinked', 'sub'] as $name) {
            $this->assertFinding($report, Finding::AcmeFile, $base.'/'.$name);
        }
    }

    public function test_served_places_are_looked_at_the_same_way(): void
    {
        config(['foundation.integrity.served' => ['public/uploads']]);
        $this->writeFile('public/uploads/a.png', 'x');
        $this->writeFile('public/uploads/b.phtml', 'x');

        $report = $this->check();

        $this->assertFinding($report, Finding::ServedPhp, 'public/uploads/b.phtml');
        $this->assertNoFinding($report, Finding::ServedPhp, 'public/uploads/a.png');
    }

    public function test_with_no_snapshot_the_served_places_storage_and_maintenance_still_run_on_the_current_settings(): void
    {
        unlink(Snapshot::path());
        config(['filesystems.links' => [$this->root.'/public/storage' => $this->root.'/storage/app/public']]);
        $this->writeFile('storage/app/public/x.php', '<?php');
        $this->writeFile('storage/app/y.php', '<?php');
        $this->writeFile('storage/framework/maintenance.php', '<?php evil();');

        $report = $this->check();

        $this->assertGap($report, Gap::NoSnapshot);
        $this->assertFinding($report, Finding::ServedPhp, 'storage/app/public/x.php');
        $this->assertFinding($report, Finding::StoragePhp, 'storage/app/y.php');
        $this->assertFinding($report, Finding::MaintenanceEntry, 'storage/framework/maintenance.php');
    }

    public function test_served_set_in_either_the_snapshot_or_now_is_looked_at(): void
    {
        $this->writeFile('public/old-uploads/x.php', '<?php');
        $this->writeFile('public/new-uploads/y.php', '<?php');
        config(['foundation.integrity.served' => ['public/old-uploads']]);
        $this->snapshot();
        config(['foundation.integrity.served' => ['public/new-uploads']]);

        $report = $this->check();

        $this->assertFinding($report, Finding::ServedPhp, 'public/old-uploads/x.php');
        $this->assertFinding($report, Finding::ServedPhp, 'public/new-uploads/y.php');
        $this->assertGap($report, Gap::SettingsDiffer);
    }

    public function test_storage_php_is_found_but_compiled_views_maintenance_and_allowed_are_not(): void
    {
        $this->writeFile('storage/app/x.php', '<?php');
        $this->writeFile('storage/logs/x.phtml', 'x');
        $this->writeFile('storage/framework/views/abc.php', '<?php');
        $this->writeFile('storage/framework/views/sub/x.php', '<?php');
        $this->writeFile('storage/app/allowed/ok.php', '<?php');
        config(['foundation.integrity.storage_php_allow' => ['app/allowed']]);
        $this->snapshot();
        posix_mkfifo($this->root.'/storage/app/pipe.php', 0644);

        $result = $this->inChild(fn () => $this->findings($this->check()), 10);
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $findings = $result['result']['result'];

        $this->assertContains('storage-php:storage/app/x.php', $findings);
        $this->assertContains('storage-php:storage/logs/x.phtml', $findings);
        $this->assertContains('storage-php:storage/framework/views/sub/x.php', $findings);
        $this->assertContains('storage-php:storage/app/pipe.php', $findings);
        $this->assertNotContains('storage-php:storage/framework/views/abc.php', $findings);
        $this->assertNotContains('storage-php:storage/app/allowed/ok.php', $findings);
    }

    public function test_maintenance_written_by_artisan_down_only_tells_it_is_in_maintenance(): void
    {
        Artisan::call('down');
        $report = $this->check();
        $this->assertFinding($report, Finding::InMaintenance, 'storage/framework/down');
        $this->assertNoFinding($report, Finding::MaintenanceEntry, 'storage/framework/maintenance.php');
        $this->assertNoFinding($report, Finding::MaintenanceData, 'storage/framework/down');

        Artisan::call('up');
        $this->assertSame([], $this->findings($this->check()));
    }

    public function test_finds_an_entry_unlike_the_stub_of_any_size_and_a_link(): void
    {
        Artisan::call('down');
        $stub = (string) file_get_contents($this->root.'/storage/framework/maintenance.php');

        $this->writeFile('storage/framework/maintenance.php', strrev($stub));
        $this->assertFinding($this->check(), Finding::MaintenanceEntry, 'storage/framework/maintenance.php');

        $this->writeFile('storage/framework/maintenance.php', $stub.' ');
        $this->assertFinding($this->check(), Finding::MaintenanceEntry, 'storage/framework/maintenance.php');

        unlink($this->root.'/storage/framework/maintenance.php');
        symlink($this->root.'/.env', $this->root.'/storage/framework/maintenance.php');
        $this->assertFinding($this->check(), Finding::MaintenanceEntry, 'storage/framework/maintenance.php');
        Artisan::call('up');
    }

    public function test_tells_when_the_stub_to_compare_with_is_missing(): void
    {
        $this->app->instance(MaintenanceCheck::class, new MaintenanceCheck($this->root.'/no-such.stub'));
        $this->writeFile('storage/framework/maintenance.php', '<?php');

        $this->assertGap($this->check(), Gap::NoStub);
    }

    public function test_finds_down_data_not_allowed_and_never_shows_its_values(): void
    {
        foreach ([
            ['template' => '<html>x-template-value-x</html>'],
            ['redirect' => '/x-redirect-value-x'],
            ['secret' => 'x-secret-down-value-x'],
            ['refresh' => 15],
            ['except' => ['/x-not-allowed-x']],
            ['except' => 'not-a-list'],
        ] as $extra) {
            $this->writeFile('storage/framework/down', json_encode(['except' => [], 'retry' => null, 'status' => 503, ...$extra]));
            $lines = [];
            $report = $this->app->make(\Miraqceed\Foundation\Integrity\IntegrityChecker::class)->run(function (string $line) use (&$lines) {
                $lines[] = $line;
            });

            $this->assertFinding($report, Finding::MaintenanceData, 'storage/framework/down');
            $text = implode("\n", $lines).implode("\n", $this->findings($report));
            $this->assertStringNotContainsString('x-template-value-x', $text);
            $this->assertStringNotContainsString('x-redirect-value-x', $text);
            $this->assertStringNotContainsString('x-secret-down-value-x', $text);
        }

        $this->writeFile('storage/framework/down', 'not json');
        $this->assertFinding($this->check(), Finding::MaintenanceData, 'storage/framework/down');
        unlink($this->root.'/storage/framework/down');
    }

    public function test_allowed_down_items_pass(): void
    {
        config(['foundation.integrity.maintenance.allow' => ['secret', 'template']]);
        $this->snapshot();
        $this->writeFile('storage/framework/down', json_encode(['except' => [], 'secret' => 'x', 'template' => 'y']));

        $report = $this->check();

        $this->assertNoFinding($report, Finding::MaintenanceData, 'storage/framework/down');
        $this->assertFinding($report, Finding::InMaintenance, 'storage/framework/down');
        unlink($this->root.'/storage/framework/down');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_a_pipe_or_a_huge_down_does_not_stop_or_crash_the_check(): void
    {
        posix_mkfifo($this->root.'/storage/framework/down', 0644);
        posix_mkfifo($this->root.'/storage/framework/maintenance.php', 0644);
        $result = $this->inChild(fn () => $this->findings($this->check()), 10);
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertContains('maintenance-data:storage/framework/down', $result['result']['result']);
        $this->assertContains('maintenance-entry:storage/framework/maintenance.php', $result['result']['result']);
        unlink($this->root.'/storage/framework/down');
        unlink($this->root.'/storage/framework/maintenance.php');

        $handle = fopen($this->root.'/storage/framework/down', 'w');
        ftruncate($handle, 1024 * 1024 * 1024);
        fclose($handle);
        $this->writeFile('public/index.php', "<?php // changed\n");
        $result = $this->inChild(fn () => $this->findings($this->check()), 20, '128M');
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertContains('maintenance-data:storage/framework/down', $result['result']['result']);
        $this->assertContains('changed:public/index.php', $result['result']['result']);
        unlink($this->root.'/storage/framework/down');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_a_down_at_the_size_limit_with_short_items_is_read_within_128m(): void
    {
        $limit = \Miraqceed\Foundation\Integrity\Limits::downBytes();
        $items = [];
        $json = '{"except":[';
        while (strlen($json) < $limit - 64) {
            $json .= '"/a",';
        }
        $json = rtrim($json, ',').']}';
        $this->writeFile('storage/framework/down', $json);

        $result = $this->inChild(function () {
            $findings = $this->findings($this->check());

            return ['findings' => $findings, 'peak' => memory_get_peak_usage()];
        }, 20, '128M');

        $this->measured(sprintf("\n[memory] down at the limit (%d bytes, short items): peak %.1f MB\n", strlen($json), ($result['result']['result']['peak'] ?? 0) / 1048576));
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertContains('maintenance-data:storage/framework/down', $result['result']['result']['findings']);
        unlink($this->root.'/storage/framework/down');
    }
}
