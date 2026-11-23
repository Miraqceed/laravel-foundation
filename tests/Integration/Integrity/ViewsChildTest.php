<?php

namespace Miraqceed\Foundation\Tests\Integration\Integrity;

use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\MemoryGuard;
use Miraqceed\Foundation\Integrity\Report;
use Miraqceed\Foundation\Integrity\Settings;
use Miraqceed\Foundation\Integrity\ViewsProcess;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 画面の調べの子プロセス：本物の PHP の子（試しの入り口 tests/Support/Integrity/views-child.php）を流す。
 * 子を落とす・汚す形でも、親は落ちずに「画面の調べが落ちた」を出し、子が落ちる前に渡した結果は出す
 */
class ViewsChildTest extends TestCase
{
    private string $repo;

    private string $views;

    private string $compiled;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->repo = (string) realpath(__DIR__.'/../../..');
        $this->views = $this->repo.'/.integrity-views-'.bin2hex(random_bytes(4));
        $this->compiled = sys_get_temp_dir().'/foundation-integrity-compiled-'.bin2hex(random_bytes(4));
        mkdir($this->views, 0775, true);
        mkdir($this->compiled, 0775, true);
        file_put_contents($this->views.'/a.blade.php', '<p>{{ $a }}</p>');
        file_put_contents($this->views.'/b.blade.php', '<p>{{ $b }}</p>');
        $app['config']->set('view.paths', [$this->views]);
        $app['config']->set('view.compiled', $this->compiled);
        $app['config']->set('foundation.integrity.root', $this->repo);
    }

    protected function tearDown(): void
    {
        self::env('INTEGRITY_CHILD_MODE', null);
        self::env('INTEGRITY_CHILD_ENV_FILE', null);
        self::env('FOUNDATION_TEST_MARK', null);
        self::env('INTEGRITY_CHILD_VIEW_PATHS', null);
        exec('rm -rf '.escapeshellarg($this->views).' '.escapeshellarg($this->compiled));
        parent::tearDown();
    }

    /**
     * 親の環境の変数として置く（Symfony Process は、親の getenv() のうち $_SERVER にもあるものと $_ENV を子に渡す。
     * 本物の環境の変数は両方にある）
     */
    private static function env(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);

            return;
        }
        putenv($name.'='.$value);
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }

    /** @return array{0: Report, 1: string, 2: float} */
    private function runChild(string $mode = '', float $seconds = 60): array
    {
        self::env('INTEGRITY_CHILD_MODE', $mode);
        $report = new Report;
        $context = new Context($this->repo, Settings::current($this->repo), new Deadline($seconds), new MemoryGuard, $report);
        $process = new ViewsProcess(ViewsProcess::testCommand(__DIR__.'/../../Support/Integrity/views-child.php'));
        $started = microtime(true);
        $mode = $process->run($context, ViewsProcess::targets($context, ['storage']));

        return [$report, $mode, microtime(true) - $started];
    }

    private function tamper(): string
    {
        view('a', ['a' => 1])->render();
        view('b', ['b' => 1])->render();
        $file = $this->compiled.'/'.basename(app('blade.compiler')->getCompiledPath($this->views.'/a.blade.php'));
        file_put_contents($file, str_replace('<p>', '<p onclick=x>', (string) file_get_contents($file)));

        return 'view-content:view.compiled/'.basename($file);
    }

    /** @return list<string> */
    private function list(Report $report): array
    {
        return [...array_map(fn ($f) => $f[0]->value.':'.$f[1], $report->findings()), ...array_map(fn ($g) => $g[0]->value, $report->gaps())];
    }

    /**
     * Laravel が書く形（compileString() の出力と PATH の行）で、コンパイルした画面を書く。試しのプロセスで描く・compile() すると、
     * appendFilePath() が出力の全体を token_get_all して数百 MB を使い、試しの memory_limit に左右される。
     * $compile が偽なら、中身は書かない（見積もりで止まる画面）
     *
     * @return string 書いたファイルの道
     */
    private function writeCompiled(string $source, bool $compile): string
    {
        $compiler = app('blade.compiler');
        $compiler->setPath($source);
        $file = $this->compiled.'/'.basename($compiler->getCompiledPath($source));
        $body = $compile ? $compiler->compileString((string) file_get_contents($source)) : 'x';
        file_put_contents($file, $body."<?php /**PATH {$source} ENDPATH**/ ?>");

        return $file;
    }

    public function test_the_child_returns_the_results(): void
    {
        $expected = $this->tamper();

        [$report, $mode] = $this->runChild();

        $this->assertSame(ViewsProcess::CHILD, $mode);
        $this->assertSame([$expected], $this->list($report));
    }

    /**
     * 広がる印の詰まった画面（@props を並べた元）も、印の上限の内なら、子の memory_limit 128M で通る。
     * compileString() の出力の全体を token_get_all する所（appendFilePath()）を通さないため
     */
    public function test_a_props_dense_view_within_the_mark_limit_passes_in_a_128m_child(): void
    {
        $source = $this->views.'/props.blade.php';
        file_put_contents($source, str_repeat("@props(['a'])\n", 6_000));
        $this->writeCompiled($source, true);

        [$report] = $this->runChild('limit-128m');

        $this->assertSame([], $this->list($report));
    }

    /**
     * 部品（<x-…>）の印は1つで約 11〜15KB を使うので、別の重さで見積もる。見積もりの内の画面は 64M の子で通り、
     * 子に許す分（網の残りの 50%）を超える画面は、コンパイルせずに名前を出す
     */
    public function test_component_dense_views_are_estimated_by_the_component_weight(): void
    {
        mkdir($this->views.'/components');
        file_put_contents($this->views.'/components/alert.blade.php', '@props(["type" => "a", "message" => ""])<div class="{{ $type }}">{{ $message }}</div>');
        self::env('INTEGRITY_CHILD_VIEW_PATHS', $this->views);
        $write = function (string $name, int $tags, bool $compile): string {
            $path = $this->views.'/'.$name.'.blade.php';
            file_put_contents($path, str_repeat("<x-alert type=\"a\" :message=\"\$m\" />\n", $tags));

            return basename($this->writeCompiled($path, $compile));
        };
        $few = $write('few', 300, true);
        // 3,000 個：前の重さ（2KB）の見積もりは約 13MB で通るが、compileString() は約 46MB を使い、128M の子に許す分を超える。
        // 見積もりで止まるので、書いたものの中身は比べない（試しのプロセスでもコンパイルしない）
        $many = $write('many', 3_000, false);
        $this->assertFileExists($this->compiled.'/'.$few);
        $this->assertFileExists($this->compiled.'/'.$many);

        [$report] = $this->runChild('limit-64m');
        $this->assertNotContains('view-content:view.compiled/'.$few, $this->list($report));
        $this->assertNotContains('views-failed', $this->list($report));

        [$report] = $this->runChild('limit-128m');
        $tooLarge = array_values(array_filter(array_map(fn ($g) => $g[0]->value.':'.$g[1], $report->gaps()), fn (string $g) => str_starts_with($g, 'view-too-large')));
        $this->assertSame(['view-too-large:view.compiled/'.$many], $tooLarge);
        $this->assertNotContains('views-failed', $this->list($report));
        $this->assertSame([], $report->findings());
    }

    public function test_warnings_before_the_head_line_do_not_spoil_the_output(): void
    {
        $expected = $this->tamper();

        [$report] = $this->runChild('warning');

        $this->assertSame([$expected], $this->list($report));
    }

    public function test_the_parents_environment_reaches_the_child(): void
    {
        $file = sys_get_temp_dir().'/foundation-integrity-env-'.bin2hex(random_bytes(4));
        self::env('INTEGRITY_CHILD_ENV_FILE', $file);
        self::env('FOUNDATION_TEST_MARK', 'made-up-mark');

        $this->runChild();

        $this->assertSame('made-up-mark', (string) @file_get_contents($file));
        @unlink($file);
    }

    public function test_a_child_that_is_not_cli_means_the_child_cannot_be_used(): void
    {
        [$report, $mode] = $this->runChild('sapi');

        $this->assertSame(ViewsProcess::UNAVAILABLE, $mode);
        $this->assertFalse($report->hasGap(Gap::ViewsFailed));
    }

    /** @return iterable<string, array{0: string}> */
    public static function brokenChildren(): iterable
    {
        yield 'runs out of memory before starting' => ['memory'];
        yield 'runs out of memory while compiling' => ['compile-crash'];
        yield 'writes a malformed line' => ['garbage'];
        yield 'returns an unknown kind' => ['bad-kind'];
        yield 'returns a path outside the views folder' => ['outside'];
        yield 'ends with 1' => ['exit1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenChildren')]
    public function test_a_broken_child_is_told_and_the_parent_ends_properly(string $mode): void
    {
        if ($mode === 'compile-crash') {
            file_put_contents($this->views.'/dense.blade.php', str_repeat("<?php ;?>\n", 200_000));
            $source = $this->views.'/dense.blade.php';
            $compiler = app('blade.compiler');
            file_put_contents($this->compiled.'/'.basename($compiler->getCompiledPath($source)), "x<?php /**PATH {$source} ENDPATH**/ ?>");
        }

        [$report, $result] = $this->runChild($mode);

        $this->assertSame(ViewsProcess::CHILD, $result);
        $this->assertTrue($report->hasGap(Gap::ViewsFailed), implode(', ', $this->list($report)));
        $this->assertStringNotContainsString('.env', implode("\n", $this->list($report)));
    }

    public function test_a_child_that_takes_too_long_is_stopped_at_the_deadline_and_not_started_again(): void
    {
        [$report, , $seconds] = $this->runChild('sleep', 2);

        // 時間切れは始め直さず、残りを「確かめられなかった（時間）」にする
        $this->assertContains('timed-out:views', $this->gapLines($report));
        $this->assertFalse($report->hasGap(Gap::ViewsFailed));
        $this->assertLessThan(15, $seconds);
    }

    /** @return list<string> */
    private function gapLines(Report $report): array
    {
        return array_map(fn ($g) => $g[0]->value.($g[1] === '' ? '' : ':'.$g[1]), $report->gaps());
    }

    /**
     * 画面を置いて、親でコンパイルする（子の前処理だけが落ちる）。印の字を含む画面は、子の前処理がメモリーを使い切る
     *
     * @return string 画面のフォルダーでの表示の道
     */
    private function compiledView(string $name, string $blade): string
    {
        $path = $this->views.'/'.$name.'.blade.php';
        file_put_contents($path, $blade);
        $compiler = app('blade.compiler');
        $compiler->compile($path);

        return 'view.compiled/'.basename($compiler->getCompiledPath($path));
    }

    /** 書き換えた画面（中身が違う） */
    private function tamperedView(string $name): string
    {
        $shown = $this->compiledView($name, '<p>{{ $'.$name.' }}</p>');
        $file = $this->compiled.'/'.basename($shown);
        file_put_contents($file, str_replace('<p>', '<p onclick=x>', (string) file_get_contents($file)));

        return $shown;
    }

    public function test_a_child_that_dies_on_one_view_names_only_that_view_and_the_rest_are_checked(): void
    {
        $crash = $this->compiledView('crash', "<p>INTEGRITY-CRASH-HERE</p>\n");
        $tampered = [];
        foreach (['t1', 't2', 't3', 't4', 't5', 't6'] as $name) {
            $tampered[] = 'view-content:'.$this->tamperedView($name);
        }
        $this->compiledView('clean', '<p>{{ $clean }}</p>');

        [$report, $mode] = $this->runChild('precompiler-crash');

        $this->assertSame(ViewsProcess::CHILD, $mode);
        $this->assertSame(['views-failed:'.$crash], array_values(array_filter($this->gapLines($report), fn ($g) => str_starts_with($g, 'views-failed'))));
        $this->assertSame([], array_values(array_diff($tampered, $this->list($report))));
        $this->assertCount(6, $report->findings());
    }

    public function test_restarting_stops_at_the_limit_and_the_rest_is_told_as_not_checked(): void
    {
        $crashes = [];
        for ($i = 1; $i <= 6; $i++) {
            $crashes[] = 'views-failed:'.$this->compiledView('crash'.$i, "<p>INTEGRITY-CRASH-HERE {$i}</p>\n");
        }

        [$report] = $this->runChild('precompiler-crash');

        $failed = array_values(array_filter($this->gapLines($report), fn ($g) => str_starts_with($g, 'views-failed')));
        // 初めの子と、始め直した3つの子が、それぞれ1つの画面で落ちる。残りはまとめて「画面の調べが落ちた」
        $this->assertCount(1 + \Miraqceed\Foundation\Integrity\Limits::VIEWS_RESTARTS + 1, $failed);
        $this->assertContains('views-failed', $failed);
        $this->assertCount(1 + \Miraqceed\Foundation\Integrity\Limits::VIEWS_RESTARTS, array_intersect($failed, $crashes));
    }

    /** 時間切れのとき、時間を使い切った画面（最後に始めた画面）の名前も出す。始め直さない */
    public function test_a_child_that_runs_out_of_time_on_one_view_names_it(): void
    {
        $slow = $this->compiledView('slow', "<p>INTEGRITY-SLOW-HERE</p>\n");

        [$report, , $seconds] = $this->runChild('precompiler-slow', 3);

        $lines = $this->gapLines($report);
        $this->assertContains('timed-out:'.$slow, $lines);
        $this->assertContains('timed-out:views', $lines);
        $this->assertFalse($report->hasGap(Gap::ViewsFailed));
        $this->assertLessThan(15, $seconds);
    }

    /**
     * 子も名前の件数の上限（違い 1,000）を持ち、超えた分は数だけを返す。画面のフォルダーに名前を撒かれても、出力の上限で
     * 画面の調べが丸ごと落ちず、種類ごとの数は合う
     */
    public function test_a_child_returns_names_up_to_the_limit_and_counts_the_rest(): void
    {
        $this->tamperedView('t1');
        // 名前をすべて返すと、子の出力が上限（4MB）を超える数（1行約 100 バイト）
        $strays = 60_000;
        for ($i = 0; $i < $strays; $i++) {
            touch($this->compiled.'/stray-'.str_pad((string) $i, 20, '0', STR_PAD_LEFT).'.txt');
        }

        [$report] = $this->runChild();

        $this->assertFalse($report->hasGap(Gap::ViewsFailed), implode(', ', $this->gapLines($report)));
        $counts = $report->findingCounts();
        $this->assertSame($strays, $counts['view-stray'] ?? 0);
        $this->assertSame(1, $counts['view-content'] ?? 0);
        $this->assertLessThanOrEqual(\Miraqceed\Foundation\Integrity\Limits::FINDINGS_KEPT, count($report->findings()));
    }

    public function test_a_flooding_child_is_stopped_at_the_output_limit_and_the_parent_does_not_grow(): void
    {
        $before = memory_get_usage();
        [$report, , $seconds] = $this->runChild('flood');

        $this->assertTrue($report->hasGap(Gap::ViewsFailed));
        $this->assertLessThan(20 * 1024 * 1024, memory_get_usage() - $before);
        // 子は終わらずに書き続ける。出力の上限（Limits::CHILD_OUTPUT_BYTES、4MB）で止めなければ、期限（60秒）まで続く
        $this->assertLessThan(15, $seconds);
    }

    /**
     * 雛形の welcome.blade.php の形（約 72KB・印が少ない）は、子の memory_limit 64M でも止まらない
     * （大きさだけで見積もると、ここで「大きすぎる」になる）
     */
    public function test_a_large_ordinary_page_like_the_welcome_page_is_not_stopped_in_a_64m_child(): void
    {
        $css = str_repeat(".c{color:#123456;margin:0 0 1rem 0;padding:2px}\n", 1500);
        $page = "<!DOCTYPE html><html><head><style>{$css}</style></head><body>"
            .str_repeat("@if (\$a) <a href=\"{{ \$b }}\">Log in</a> @endif\n", 40).'</body></html>';
        $this->assertGreaterThan(70_000, strlen($page));
        file_put_contents($this->views.'/welcome.blade.php', $page);
        view('welcome', ['a' => true, 'b' => 'x'])->render();

        [$report, $mode] = $this->runChild('limit-64m');

        $this->assertSame(ViewsProcess::CHILD, $mode);
        $this->assertSame([], $this->list($report));
    }

    public function test_a_child_writing_a_lot_to_stderr_does_not_grow_the_parent_and_stderr_never_shows(): void
    {
        $expected = $this->tamper();
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        [$report] = $this->runChild('stderr-flood');
        $this->assertSame([$expected], $this->list($report));
        $this->assertLessThan(50 * 1024 * 1024, memory_get_peak_usage() - $before);

        [$report] = $this->runChild('stderr-path');
        $this->assertStringNotContainsString('x-stderr-value-x', implode("\n", $this->list($report)));
    }
}
