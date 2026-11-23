<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 調べ1（木の全体を一覧と比べる）と調べ2（public の PHP）。本物のファイル・リンク・FIFO・権限で
 */
class SnapshotCheckTest extends TestCase
{
    use IntegrityTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    public function test_a_fresh_snapshot_then_check_finds_nothing(): void
    {
        $this->assertSame(0, $this->snapshot());
        $report = $this->check();

        $this->assertSame([], $this->findings($report));
        $this->assertSame([], $this->gaps($report));
        $this->assertGreaterThan(5, $report->files);
    }

    public function test_finds_changed_added_and_removed_files(): void
    {
        $this->snapshot();
        $this->writeFile('app/Thing.php', "<?php\nclass Thing { /* changed */ }\n");
        $this->writeFile('app/Added.php', "<?php\n");
        unlink($this->root.'/routes/web.php');

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, 'app/Thing.php');
        $this->assertFinding($report, Finding::Added, 'app/Added.php');
        $this->assertFinding($report, Finding::Removed, 'routes/web.php');
    }

    public function test_keeps_links_as_their_target_and_never_follows_them(): void
    {
        symlink($this->root.'/.env', $this->root.'/public/env-link');
        $this->snapshot();
        $this->assertStringNotContainsString(bin2hex('x-secret-value-x'), (string) file_get_contents(Snapshot::path()));
        $this->assertStringNotContainsString('x-secret-value-x', (string) file_get_contents(Snapshot::path()));

        unlink($this->root.'/public/env-link');
        symlink($this->root.'/config/app.php', $this->root.'/public/env-link');
        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, 'public/env-link');
    }

    public function test_dotenv_is_kept_as_a_hash_only_and_its_value_never_shows(): void
    {
        $this->snapshot();
        $this->writeFile('.env', "APP_KEY=base64:ZmFrZS1rZXk=\nSECRET_TOKEN=x-other-secret-x\n");
        $lines = [];
        $report = $this->app->make(\Miraqceed\Foundation\Integrity\IntegrityChecker::class)->run(function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertFinding($report, Finding::Changed, '.env');
        $this->assertStringNotContainsString('x-other-secret-x', implode("\n", $lines));
        $this->assertStringNotContainsString('x-secret-value-x', (string) file_get_contents(Snapshot::path()));
    }

    public function test_names_that_are_not_utf8_or_hold_line_breaks_are_kept_and_compared(): void
    {
        $this->writeFile("app/\xff\xfe.php", 'a');
        $this->writeFile("app/line\nbreak.php", 'b');
        $this->snapshot();
        $this->writeFile("app/\xff\xfe.php", 'changed');

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, "app/\xff\xfe.php");
        $this->assertNoFinding($report, Finding::Changed, "app/line\nbreak.php");
    }

    public function test_git_hooks_config_and_attributes_are_listed_but_objects_and_info_refs_are_not(): void
    {
        foreach (['.git/hooks/pre-commit.sample', '.git/config', '.git/objects/ab/cdef', '.git/info/refs', '.git/HEAD', '.git/index'] as $path) {
            $this->writeFile($path, 'x');
        }
        $this->snapshot();
        $this->writeFile('.git/hooks/post-merge', "#!/bin/sh\n");
        $this->writeFile('.git/config', "[filter \"x\"]\n\tclean = touch /tmp/ran\n");
        $this->writeFile('.git/info/attributes', "*.php filter=x\n");
        $this->writeFile('.git/config.worktree', "[core]\n");
        $this->writeFile('.git/modules/sub/hooks/post-checkout', "#!/bin/sh\n");
        $this->writeFile('.git/worktrees/w/config.worktree', "[core]\n");
        $this->writeFile('.git/objects/zz/new', 'x');
        $this->writeFile('.git/info/refs', 'changed');
        $this->writeFile('.git/index', 'changed');

        $report = $this->check();

        $this->assertFinding($report, Finding::Added, '.git/hooks/post-merge');
        $this->assertFinding($report, Finding::Changed, '.git/config');
        $this->assertFinding($report, Finding::Added, '.git/info/attributes');
        $this->assertFinding($report, Finding::Added, '.git/config.worktree');
        $this->assertFinding($report, Finding::Added, '.git/modules/sub/hooks/post-checkout');
        $this->assertFinding($report, Finding::Added, '.git/worktrees/w/config.worktree');
        $joined = implode("\n", $this->findings($report));
        $this->assertStringNotContainsString('.git/objects', $joined);
        $this->assertStringNotContainsString('.git/info/refs', $joined);
        $this->assertStringNotContainsString('.git/index', $joined);
    }

    public function test_a_gitignore_or_hidden_flags_do_not_hide_anything_here(): void
    {
        $this->snapshot();
        $this->writeFile('database/migrations/.gitignore', "*\n");
        $this->writeFile('database/migrations/2026_evil.php', "<?php\n");

        $report = $this->check();

        $this->assertFinding($report, Finding::Added, 'database/migrations/2026_evil.php');
    }

    public function test_storage_is_not_in_the_list(): void
    {
        $this->snapshot();
        $this->writeFile('storage/logs/laravel.log', 'line');

        $this->assertSame([], $this->findings($this->check()));
    }

    public function test_acme_and_served_places_are_out_of_the_list(): void
    {
        config(['foundation.integrity.served' => ['public/uploads']]);
        $this->writeFile('public/.well-known/acme-challenge/tokenA', 'x');
        $this->writeFile('public/uploads/a.png', 'x');
        $this->snapshot();
        unlink($this->root.'/public/.well-known/acme-challenge/tokenA');
        $this->writeFile('public/.well-known/acme-challenge/tokenB', 'y');
        $this->writeFile('public/uploads/b.png', 'y');

        $report = $this->check();

        $joined = implode("\n", $this->findings($report));
        $this->assertStringNotContainsString('acme-challenge/token', $joined);
        $this->assertStringNotContainsString('uploads/', $joined);
    }

    public function test_finds_unreadable_files_and_non_regular_files(): void
    {
        $this->requireNotRoot();
        $this->snapshot();
        chmod($this->writeFile('app/Locked.php', 'x'), 0000);
        posix_mkfifo($this->root.'/app/pipe', 0644);

        $report = $this->check();

        $this->assertFinding($report, Finding::Added, 'app/Locked.php');
        $this->assertFinding($report, Finding::NotRegular, 'app/pipe');
    }

    public function test_a_changed_file_that_cannot_be_read_is_unreadable(): void
    {
        $this->requireNotRoot();
        $this->snapshot();
        chmod($this->root.'/app/Thing.php', 0000);

        $this->assertFinding($this->check(), Finding::Unreadable, 'app/Thing.php');
    }

    public function test_one_unopened_folder_hides_nothing_else(): void
    {
        $this->requireNotRoot();
        $this->snapshot();
        chmod($this->root.'/vendor/acme', 0000);
        $this->writeFile('public/index.php', "<?php\n// changed\n");

        $report = $this->check();

        $this->assertFinding($report, Finding::Changed, 'public/index.php');
        $this->assertContains('unopened:vendor/acme', $this->gaps($report));
        // 開けないフォルダーの下の一覧の道は「消えた」にしない
        $this->assertNoFinding($report, Finding::Removed, 'vendor/acme/lib/src/Lib.php');
    }

    public function test_a_folder_with_execute_only_permission_hides_nothing(): void
    {
        $this->requireNotRoot();
        $this->snapshot();
        $this->writeFile('app/hide/evil.php', "<?php\n");
        chmod($this->root.'/app/hide', 0111);

        $report = $this->check();

        $this->assertContains('unopened:app/hide', $this->gaps($report));
    }

    public function test_with_no_snapshot_tells_it_and_still_finds_public_php(): void
    {
        $this->writeFile('public/shell.php', "<?php\n");

        $report = $this->check();

        $this->assertGap($report, Gap::NoSnapshot);
        $this->assertFinding($report, Finding::PublicPhp, 'public/shell.php');
    }

    public function test_a_broken_or_foreign_snapshot_is_not_used_silently(): void
    {
        $this->snapshot();
        $path = Snapshot::path();

        file_put_contents($path, "not a snapshot\n");
        $this->assertGap($this->check(), Gap::BadSnapshot);

        $this->snapshot();
        $lines = file($path);
        $lines[0] = str_replace('"root":"', '"root":"0', $lines[0]);
        file_put_contents($path, implode('', $lines));
        $this->assertGap($this->check(), Gap::OtherRoot);

        unlink($path);
        posix_mkfifo($path, 0644);
        $result = $this->inChild(fn () => $this->check()->hasGap(Gap::BadSnapshot), 10);
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertTrue($result['result']['result']);
    }

    public function test_a_truncated_snapshot_keeps_the_findings_and_adds_broken(): void
    {
        $this->snapshot();
        $this->writeFile('app/Thing.php', "<?php // changed\n");
        $path = Snapshot::path();
        $lines = file($path);
        array_pop($lines);
        file_put_contents($path, implode('', $lines));

        $report = $this->check();

        $this->assertGap($report, Gap::BrokenSnapshot);
        $this->assertFinding($report, Finding::Changed, 'app/Thing.php');
    }

    public function test_a_snapshot_with_a_line_removed_or_out_of_order_is_broken(): void
    {
        $this->snapshot();
        $path = Snapshot::path();
        $lines = file($path);

        $removed = $lines;
        array_splice($removed, 2, 1);
        file_put_contents($path, implode('', $removed));
        $this->assertGap($this->check(), Gap::BrokenSnapshot);

        $swapped = $lines;
        [$swapped[1], $swapped[2]] = [$swapped[2], $swapped[1]];
        file_put_contents($path, implode('', $swapped));
        $this->assertGap($this->check(), Gap::BrokenSnapshot);

        $doubled = $lines;
        array_splice($doubled, 2, 0, [$lines[2]]);
        file_put_contents($path, implode('', $doubled));
        $this->assertGap($this->check(), Gap::BrokenSnapshot);
    }

    public function test_public_php_finds_middle_extensions_and_passes_index(): void
    {
        foreach (['public/x.php', 'public/a/b.php5', 'public/x.php.jpg', 'public/x.phtml', 'public/x.phar', 'public/X.PHP', 'public/x.jpg', 'public/php.png'] as $path) {
            $this->writeFile($path, 'x');
        }
        $this->snapshot();

        $report = $this->check();

        foreach (['public/x.php', 'public/a/b.php5', 'public/x.php.jpg', 'public/x.phtml', 'public/x.phar', 'public/X.PHP'] as $path) {
            $this->assertFinding($report, Finding::PublicPhp, $path);
        }
        foreach (['public/index.php', 'public/x.jpg', 'public/php.png'] as $path) {
            $this->assertNoFinding($report, Finding::PublicPhp, $path);
        }
    }

    public function test_adding_to_exclude_does_not_hide_until_the_snapshot_is_made_again(): void
    {
        $this->snapshot();
        config(['foundation.integrity.exclude' => ['database', 'public/x.php']]);
        $this->writeFile('public/x.php', "<?php\n");
        $this->writeFile('database/seed.php', "<?php\n");

        $report = $this->check();

        $this->assertFinding($report, Finding::Added, 'public/x.php');
        $this->assertFinding($report, Finding::Added, 'database/seed.php');
        $this->assertGap($report, Gap::SettingsDiffer);
    }

    public function test_changing_only_the_snapshot_settings_does_not_hide_either(): void
    {
        $this->snapshot();
        $path = Snapshot::path();
        $lines = file($path);
        $lines[0] = str_replace('"exclude":[]', '"exclude":["app"]', $lines[0]);
        file_put_contents($path, implode('', $lines));
        $this->writeFile('app/Thing.php', "<?php // changed\n");

        $report = $this->check();

        // 頭の行を書き換えると、指紋の範囲（項目の行）は合うが、設定が今と違う。厳しい方（今の設定）で外さないので、違いは出る
        $this->assertFinding($report, Finding::Changed, 'app/Thing.php');
        $this->assertGap($report, Gap::SettingsDiffer);
    }

    public function test_tells_when_exclude_served_or_acme_cover_what_the_check_loads(): void
    {
        foreach ([['foundation.integrity.exclude' => ['app']], ['foundation.integrity.served' => ['vendor/acme']], ['foundation.integrity.acme' => 'config'], ['foundation.integrity.exclude' => ['.env']]] as $settings) {
            config(['foundation.integrity.exclude' => [], 'foundation.integrity.served' => [], 'foundation.integrity.acme' => 'public/.well-known/acme-challenge']);
            config($settings);

            $this->assertGap($this->check(), Gap::ExcludesLoadPaths);
        }
    }

    public function test_tells_when_the_public_directory_is_outside_the_tree(): void
    {
        $outside = sys_get_temp_dir().'/foundation-integrity-public-'.bin2hex(random_bytes(4));
        mkdir($outside);
        try {
            $this->app->usePublicPath($outside);
            $this->assertGap($this->check(), Gap::PublicOutside);
            $this->assertSame(1, $this->snapshot());
        } finally {
            rmdir($outside);
        }
    }

    public function test_tells_when_the_trees_public_is_a_link(): void
    {
        rename($this->root.'/public', $this->root.'/public-real');
        symlink($this->root.'/public-real', $this->root.'/public');
        $this->app->usePublicPath($this->root.'/public');

        $this->assertGap($this->check(), Gap::PublicOutside);
    }

    public function test_numeric_names_are_ordered_by_bytes_and_do_not_give_false_differences(): void
    {
        foreach (['9', '10', '1e1', '010', '1.0', '01', 'a', 'a.b'] as $name) {
            $this->writeFile('app/n/'.$name, $name);
        }
        $this->writeFile('app/n/a.d/x', 'x');
        $this->snapshot();

        $this->assertSame([], $this->findings($this->check()));
    }

    public function test_changing_the_kind_at_the_top_level_keeps_the_order(): void
    {
        $this->writeFile('.git/config', 'x');
        $this->snapshot();
        exec('rm -rf '.escapeshellarg($this->root.'/.git'));
        $this->writeFile('.git', "gitdir: /elsewhere\n");
        rename($this->root.'/vendor', $this->root.'/vendor-real');
        symlink($this->root.'/vendor-real', $this->root.'/vendor');

        $report = $this->check();

        $this->assertNotContains('broken-snapshot', array_map(fn ($g) => $g[0]->value, $report->gaps()));
        $this->assertFinding($report, Finding::Added, '.git');
        $this->assertFinding($report, Finding::Removed, '.git/config');
        $this->assertFinding($report, Finding::Added, 'vendor');
        $this->assertFinding($report, Finding::Removed, 'vendor/autoload.php');
    }

    public function test_a_folder_with_too_many_names_is_told_and_php_there_still_shows(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->writeFile('public/many/f'.$i.'.txt', 'x');
        }
        $this->assertSame(0, $this->snapshot());
        Limits::overrideForTests(['n' => 50]);
        $this->writeFile('public/many/x.php', "<?php\n");
        $this->writeFile('public/many/sub/y.phtml', 'x');
        symlink($this->root.'/.env', $this->root.'/public/many/env');
        $this->writeFile('public/index.php', "<?php // changed\n");

        $report = $this->check();

        $this->assertContains('too-many:public/many', $this->gaps($report));
        $this->assertFinding($report, Finding::PublicPhp, 'public/many/x.php');
        $this->assertFinding($report, Finding::PublicPhp, 'public/many/sub/y.phtml');
        $this->assertFinding($report, Finding::ServedLink, 'public/many/env');
        $this->assertFinding($report, Finding::Changed, 'public/index.php');
        $this->assertNoFinding($report, Finding::Removed, 'public/many/f1.txt');
    }

    public function test_a_tree_deeper_than_the_limit_is_told(): void
    {
        $this->assertSame(0, $this->snapshot());
        Limits::overrideForTests(['depth' => 8]);
        $this->writeFile('app/'.implode('/', array_fill(0, 10, 'd')).'/deep.php', 'x');
        $this->writeFile('config/app.php', "<?php // changed\n");

        $report = $this->check();

        $this->assertGap($report, Gap::TooDeep);
        $this->assertFinding($report, Finding::Changed, 'config/app.php');
    }

    public function test_a_real_257_deep_tree_is_told_with_the_real_limit(): void
    {
        $path = $this->root.'/app';
        for ($i = 0; $i < 257; $i++) {
            $path .= '/d';
        }
        mkdir($path, 0775, true);
        file_put_contents($path.'/x.php', 'x');
        $this->snapshot();

        $this->assertGap($this->check(), Gap::TooDeep);
    }

    public function test_when_time_runs_out_what_was_seen_shows_and_removed_is_not_guessed(): void
    {
        $this->snapshot();
        $this->writeFile('public/index.php', "<?php // changed\n");
        config(['foundation.integrity.seconds' => 0]);

        $report = $this->check();

        $this->assertGap($report, Gap::TimedOut);
        $this->assertSame([], array_filter($this->findings($report), fn ($f) => str_starts_with($f, 'removed:')));
    }
}
