<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Integrity\Checks\CompiledViews;
use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\MemoryGuard;
use Miraqceed\Foundation\Integrity\Report;
use Miraqceed\Foundation\Integrity\Settings;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 調べ5：コンパイルした画面。本物の Blade のコンパイラーと、view:cache・Markdown の描画が実際に書いたファイルで。
 * 元の Blade は木の中にあること、なので木の根はこの倉庫（vendor の Laravel の画面も木の中）。画面のフォルダーは木の外の一時の所
 */
class CompiledViewsTest extends TestCase
{
    use \Miraqceed\Foundation\Tests\Support\Integrity\OwnMemoryLimit;

    private string $repo;

    private string $views;

    private string $compiled;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->liftMemoryLimit();
        $this->repo = (string) realpath(__DIR__.'/../../..');
        $this->views = $this->repo.'/.integrity-views-'.bin2hex(random_bytes(4));
        $this->compiled = sys_get_temp_dir().'/foundation-integrity-compiled-'.bin2hex(random_bytes(4));
        mkdir($this->views.'/components', 0775, true);
        mkdir($this->compiled, 0775, true);
        file_put_contents($this->views.'/page.blade.php', "<x-box title=\"{{ \$t }}\">Hi {{ \$name }}</x-box>\n@include('part')\n@once<script>1</script>@endonce\n@pushOnce('s')<b>2</b>@endPushOnce\n");
        file_put_contents($this->views.'/part.blade.php', "<p>{!! \$x !!}</p>@if(\$a) a @endif");
        file_put_contents($this->views.'/components/box.blade.php', "@props(['title'])\n<div>{{ \$title }}{{ \$slot }}</div>\n");
        file_put_contents($this->views.'/plain.blade.php', '<p>no php at the end</p>');
        file_put_contents($this->views.'/endsphp.blade.php', '<p>x</p>@php $a = 1 @endphp');
        $app['config']->set('view.paths', [$this->views]);
        $app['config']->set('view.compiled', $this->compiled);
        $app['config']->set('foundation.integrity.root', $this->repo);
    }

    protected function tearDown(): void
    {
        Limits::overrideForTests(null);
        exec('rm -rf '.escapeshellarg($this->views).' '.escapeshellarg($this->compiled));
        $this->restoreMemoryLimit();
        parent::tearDown();
    }

    private function report(float $ratio = 0.5): Report
    {
        $report = new Report;
        $context = new Context($this->repo, Settings::current($this->repo), new Deadline(60), new MemoryGuard, $report);
        $targets = ViewsProcess::targets($context, ['storage']);
        $checker = new CompiledViews($this->repo, $targets['sources'], $targets['excluded'], $context->deadline, $context->guard, $ratio,
            fn (Finding|Gap $kind, string $path) => $kind instanceof Finding ? $report->add($kind, $path) : $report->notChecked($kind, $path));
        $stopped = $checker->check($this->compiled, 'view.compiled');
        if ($stopped !== null) {
            $report->notChecked($stopped);
        }

        return $report;
    }

    /** @return list<string> */
    private function list(Report $report): array
    {
        return [...array_map(fn ($f) => $f[0]->value.':'.$f[1], $report->findings()), ...array_map(fn ($g) => $g[0]->value.':'.$g[1], $report->gaps())];
    }

    private function compiledOf(string $view): string
    {
        return $this->compiled.'/'.basename(app('blade.compiler')->getCompiledPath(app('view')->getFinder()->find($view)));
    }

    public function test_passes_views_compiled_by_view_cache_including_package_views(): void
    {
        Artisan::call('view:cache');
        $count = count(glob($this->compiled.'/*.php'));
        $this->assertGreaterThan(5, $count);

        $this->assertSame([], $this->list($this->report()));
    }

    public function test_passes_markdown_mail_views_compiled_with_the_mail_echo(): void
    {
        (new MailMessage)->greeting('Hello')->line('A line with <b>tags</b>')->action('Go', 'https://example.com/x')->render();
        $this->assertGreaterThan(3, count(glob($this->compiled.'/*.php')));

        $this->assertSame([], $this->list($this->report()));
    }

    public function test_passes_views_rendered_through_the_view_factory(): void
    {
        view('page', ['t' => 'T', 'name' => 'N', 'x' => 'X', 'a' => true])->render();
        view('plain')->render();
        view('endsphp')->render();

        $this->assertSame([], $this->list($this->report()));
    }

    public function test_finds_changed_content_a_wrong_name_and_a_missing_source(): void
    {
        view('part', ['x' => 1, 'a' => 0])->render();
        view('plain')->render();
        $part = $this->compiledOf('part');
        file_put_contents($part, str_replace('<p>', '<p onload=evil()>', (string) file_get_contents($part)));
        rename($this->compiledOf('plain'), $this->compiled.'/0123456789abcdef0123456789abcdef.php');
        file_put_contents($this->compiled.'/feedfacefeedfacefeedfacefeedface.php', '<p>x</p><?php /**PATH '.$this->views.'/gone.blade.php ENDPATH**/ ?>');
        file_put_contents($this->compiled.'/nofooter.php', '<?php evil();');

        $list = $this->list($this->report());

        $this->assertContains('view-content:view.compiled/'.basename($part), $list);
        $this->assertContains('view-name:view.compiled/0123456789abcdef0123456789abcdef.php', $list);
        $this->assertContains('view-without-source:view.compiled/feedfacefeedfacefeedfacefeedface.php', $list);
        $this->assertContains('view-without-source:view.compiled/nofooter.php', $list);
    }

    public function test_a_source_outside_the_tree_or_in_storage_is_without_source(): void
    {
        $outside = sys_get_temp_dir().'/foundation-integrity-blade-'.bin2hex(random_bytes(4)).'.blade.php';
        file_put_contents($outside, '<p>x</p>');
        $compiler = app('blade.compiler');
        $compiler->setPath($outside);
        $name = basename($compiler->getCompiledPath($outside));
        file_put_contents($this->compiled.'/'.$name, $compiler->compileString('<p>x</p>')."<?php /**PATH {$outside} ENDPATH**/ ?>");

        $this->assertContains('view-without-source:view.compiled/'.$name, $this->list($this->report()));
        unlink($outside);
    }

    public function test_only_uuid_shaped_once_ids_may_differ(): void
    {
        view('page', ['t' => 'T', 'name' => 'N', 'x' => 'X', 'a' => true])->render();
        $page = $this->compiledOf('page');
        $contents = (string) file_get_contents($page);
        $this->assertMatchesRegularExpression("/'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'/", $contents);

        file_put_contents($page, preg_replace("/'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'/", "'00000000-1111-2222-3333-444444444444'", $contents));
        $this->assertSame([], $this->list($this->report()));

        file_put_contents($page, preg_replace("/'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'/", "'evil()'", $contents, 1));
        $this->assertContains('view-content:view.compiled/'.basename($page), $this->list($this->report()));
    }

    public function test_things_that_do_not_belong_in_the_views_folder_are_named(): void
    {
        mkdir($this->compiled.'/sub');
        file_put_contents($this->compiled.'/sub/x.php', '<?php');
        file_put_contents($this->compiled.'/x.phtml', 'x');
        file_put_contents($this->compiled.'/.gitignore', "*\n");
        symlink($this->views.'/plain.blade.php', $this->compiled.'/linked.php');
        posix_mkfifo($this->compiled.'/pipe.php', 0644);

        $list = $this->list($this->report());

        $this->assertContains('view-stray:view.compiled/sub/x.php', $list);
        $this->assertContains('view-stray:view.compiled/x.phtml', $list);
        $this->assertContains('view-stray:view.compiled/linked.php', $list);
        $this->assertContains('view-stray:view.compiled/pipe.php', $list);
        $this->assertNotContains('view-stray:view.compiled/.gitignore', $list);
    }

    public function test_a_view_too_large_or_too_dense_is_told_not_compiled(): void
    {
        $dense = str_repeat("@props(['a'])\n", 140_000);
        file_put_contents($this->views.'/dense.blade.php', $dense);
        $compiler = app('blade.compiler');
        $source = $this->views.'/dense.blade.php';
        $name = basename($compiler->getCompiledPath($source));
        file_put_contents($this->compiled.'/'.$name, "x<?php /**PATH {$source} ENDPATH**/ ?>");

        $this->assertContains('view-too-large:view.compiled/'.$name, $this->list($this->report()));

        $huge = $this->compiled.'/'.str_repeat('a', 32).'.php';
        $handle = fopen($huge, 'w');
        ftruncate($handle, Limits::VIEW_COMPILED_BYTES + 1);
        fclose($handle);
        $this->assertContains('view-too-large:view.compiled/'.basename($huge), $this->list($this->report()));
    }

    public function test_a_large_ordinary_page_like_the_welcome_page_is_not_stopped(): void
    {
        $css = str_repeat(".c{color:#123456;margin:0 0 1rem 0;padding:2px}\n", 1500);
        $page = "<!DOCTYPE html><html><head><style>{$css}</style></head><body>"
            .str_repeat("@if (\$a) <a href=\"{{ \$b }}\">Log in</a> @endif\n", 40).'</body></html>';
        $this->assertGreaterThan(70_000, strlen($page));
        file_put_contents($this->views.'/welcome.blade.php', $page);
        view('welcome', ['a' => true, 'b' => 'x'])->render();

        $this->assertSame([], $this->list($this->report()));
    }
}
