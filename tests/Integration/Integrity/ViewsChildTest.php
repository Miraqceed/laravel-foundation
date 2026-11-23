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
        file_put_contents($this->views.'/props.blade.php', str_repeat("@props(['a'])\n", 6_000));
        view('props')->render();

        [$report] = $this->runChild('limit-128m');

        $this->assertSame([], $this->list($report));
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

    public function test_a_child_that_takes_too_long_is_stopped_at_the_deadline(): void
    {
        [$report, , $seconds] = $this->runChild('sleep', 2);

        $this->assertTrue($report->hasGap(Gap::ViewsFailed));
        $this->assertLessThan(15, $seconds);
    }

    public function test_a_flooding_child_is_stopped_at_one_megabyte_and_the_parent_does_not_grow(): void
    {
        $before = memory_get_usage();
        [$report, , $seconds] = $this->runChild('flood');

        $this->assertTrue($report->hasGap(Gap::ViewsFailed));
        $this->assertLessThan(20 * 1024 * 1024, memory_get_usage() - $before);
        $this->assertLessThan(30, $seconds);
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
