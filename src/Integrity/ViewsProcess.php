<?php

namespace Miraqceed\Foundation\Integrity;

use Miraqceed\Foundation\Commands\IntegrityViewsCommand;
use Miraqceed\Foundation\Integrity\Checks\CompiledViews;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * 画面の調べを、子プロセス（foundation:integrity-views）で流す親の側。
 *
 * - PHP は schedule の runInBackground() と同じ見つけ方（php_binary()。ない版は PhpExecutableFinder）
 * - -d display_errors=stderr などで始める（PHP の CLI は警告・起動の誤りを標準出力に書き、子の行の形を壊すため）
 * - 環境は親のものをそのまま渡す（同じアプリで、.env を自分で読む。PATH と HOME だけにすると設定が親とずれる）
 * - 標準出力は受け取るたびに数え、1MB を超えたらすぐ止める。標準エラーは溜めずに捨てる（道や秘密が入りうる）
 * - 行の形・種類の印・道（渡した画面のフォルダーの中か）を確かめる。外れれば「画面の調べが落ちた」。受け取った正しい結果は出す
 *
 * @internal
 */
final class ViewsProcess
{
    public const CHILD = 'child';

    public const UNAVAILABLE = 'unavailable';

    /** 子が返してよい印 */
    private const FINDINGS = ['view-without-source', 'view-name', 'view-content', 'view-stray', 'unreadable'];

    private const GAPS = ['view-too-large', 'unopened', 'too-many', 'too-deep'];

    private const STOPS = ['timed-out', 'memory'];

    /**
     * @param  list<string>|null  $command  子の命令の前の部分（試しで替える）。null なら [PHP, -d…, artisan, foundation:integrity-views]
     * @param  bool|null  $available  子を使えるか（試しで替える）。null なら proc_open が使えるか
     */
    public function __construct(private readonly ?array $command = null, private readonly ?bool $available = null) {}

    /**
     * 画面のフォルダーごとの、見る場所の組
     *
     * @return array{dirs: list<array{path: string, shown: string}>, sources: list<string>, excluded: list<string>, generated: list<string>, inline: list<string>}
     */
    public static function targets(Context $context, array $excluded): array
    {
        $dirs = [];
        $compiled = (string) config('view.compiled', '');
        if ($compiled !== '') {
            $real = realpath($compiled) ?: $compiled;
            $dirs[$real] = ['path' => $real, 'shown' => $context->shown($real, 'view.compiled')];
        }
        foreach ($context->effective()->viewCompiled as $relative) {
            if (! str_starts_with($relative, 'outside:') && $relative !== '') {
                $path = $context->root.'/'.$relative;
                $dirs[$path] ??= ['path' => $path, 'shown' => $relative];
            }
        }

        $sources = [];
        $finder = app('view')->getFinder();
        $candidates = [...(array) $finder->getPaths()];
        foreach ((array) $finder->getHints() as $paths) {
            $candidates = [...$candidates, ...(array) $paths];
        }
        try {
            $markdown = app(\Illuminate\Mail\Markdown::class);
            $candidates = [...$candidates, ...array_values((array) $markdown->htmlComponentPaths()), ...array_values((array) $markdown->textComponentPaths())];
        } catch (Throwable) {
            // Markdown を作れないアプリでも、ほかの場所で調べる
        }
        try {
            foreach ((array) app('blade.compiler')->getAnonymousComponentPaths() as $path) {
                $candidates[] = is_array($path) ? (string) ($path['path'] ?? '') : (string) $path;
            }
        } catch (Throwable) {
            // 同上
        }
        foreach ($candidates as $candidate) {
            $real = realpath((string) $candidate);
            if ($real !== false && str_starts_with(str_replace('\\', '/', $real), $context->root.'/')) {
                $sources[] = str_replace('\\', '/', $real);
            }
        }

        $effective = $context->effective();

        return ['dirs' => array_values($dirs), 'sources' => array_values(array_unique($sources)), 'excluded' => $excluded,
            'generated' => $effective->generatedFolders(), 'inline' => $effective->inlineViewDirs()];
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    /**
     * 子で流す。子を使えなければ UNAVAILABLE（呼び手が親の中で控えめに流す）
     *
     * @param  array{dirs: list<array{path: string, shown: string}>, sources: list<string>, excluded: list<string>, generated: list<string>, inline: list<string>}  $targets
     */
    public function run(Context $context, array $targets): string
    {
        if (! $this->available()) {
            return self::UNAVAILABLE;
        }
        $report = $context->report;
        $input = json_encode([
            'root' => $context->root,
            'dirs' => $targets['dirs'],
            'sources' => $targets['sources'],
            'excluded' => $targets['excluded'],
            'generated' => $targets['generated'],
            'inline' => $targets['inline'],
            'seconds' => max(1.0, $context->deadline->remaining()),
        ], JSON_UNESCAPED_SLASHES);

        // 標準エラーは OS の段で捨てる（親に溜めない。道や秘密が入りうるので読まない）。環境は指定しない（親のものをそのまま）
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        try {
            $process = @proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, base_path());
        } catch (Throwable) {
            $process = false;
        }
        if (! is_resource($process)) {
            return self::UNAVAILABLE;
        }
        @fwrite($pipes[0], (string) $input);
        @fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $shownDirs = array_map(fn ($dir) => $dir['shown'], $targets['dirs']);
        $buffer = '';
        $bytes = 0;
        $state = ['head' => false, 'end' => false, 'bad' => false, 'count' => 0, 'stopped' => null];
        $results = [];
        $exit = null;
        $signaled = false;
        $deadline = microtime(true) + max(1.0, $context->deadline->remaining());

        try {
            while (true) {
                $status = proc_get_status($process);
                if (! $status['running'] && $exit === null) {
                    $exit = (int) $status['exitcode'];
                    $signaled = (bool) $status['signaled'];
                }
                $read = [$pipes[1]];
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 0, 100_000);
                $chunk = $ready ? (string) @fread($pipes[1], 65536) : '';
                // 期限は、受け取りが続いているときも見る（書き続ける子で止まらないように）
                if (microtime(true) > $deadline) {
                    $state['bad'] = true;
                    break;
                }
                if ($chunk === '') {
                    if (! $status['running'] && feof($pipes[1])) {
                        break;
                    }
                    if (! $status['running'] && ! $ready) {
                        break;
                    }

                    continue;
                }
                // 受け取るたびに数え、1MB を超えたらすぐ止める
                $bytes += strlen($chunk);
                if ($bytes > Limits::CHILD_OUTPUT_BYTES) {
                    $state['bad'] = true;
                    break;
                }
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $this->line($line, $state, $results, $shownDirs);
                    if ($state['bad']) {
                        break 2;
                    }
                }
                if (strlen($buffer) > Limits::LINE_BYTES) {
                    $state['bad'] = true;
                    break;
                }
            }
        } catch (Throwable) {
            $state['bad'] = true;
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            @proc_terminate($process, 9);
            $state['bad'] = true;
        }
        @fclose($pipes[1]);
        $closed = @proc_close($process);
        if ($exit === null) {
            $exit = $status['running'] ? -1 : (int) $closed;
        }

        if ($exit === IntegrityViewsCommand::NOT_CLI && ! $state['head']) {
            return self::UNAVAILABLE;
        }

        foreach ($results as [$kind, $path]) {
            $kind instanceof Finding ? $report->add($kind, $path) : $report->notChecked($kind, $path);
        }
        if ($state['stopped'] !== null) {
            $report->notChecked($state['stopped'], 'views');
        }
        if ($state['bad'] || $exit !== 0 || ! $state['end'] || $signaled) {
            $report->notChecked(Gap::ViewsFailed);
        }

        return self::CHILD;
    }

    /**
     * 親の中で流す（子を使えないとき）。控えめな見積もり（網の残りの 10%）
     *
     * @param  array{dirs: list<array{path: string, shown: string}>, sources: list<string>, excluded: list<string>, generated: list<string>, inline: list<string>}  $targets
     */
    public static function inProcess(Context $context, array $targets): void
    {
        $report = $context->report;
        $checker = new CompiledViews($context->root, $targets['sources'], $targets['excluded'], $context->deadline, $context->guard, 0.1,
            fn (Finding|Gap $kind, string $path) => $kind instanceof Finding ? $report->add($kind, $path) : $report->notChecked($kind, $path),
            Limits::VIEW_MARKS_IN_PROCESS, $targets['generated'], $targets['inline']);
        foreach ($targets['dirs'] as $dir) {
            $stopped = $checker->check($dir['path'], $dir['shown']);
            if ($stopped !== null) {
                $report->notChecked($stopped, 'views');

                return;
            }
        }
    }

    /** @return list<string> */
    private function command(): array
    {
        if ($this->command !== null) {
            return [...$this->command, 'foundation:integrity-views'];
        }
        $php = function_exists('php_binary') ? php_binary() : (new PhpExecutableFinder)->find(false);

        return [(string) $php, ...self::iniOptions(), base_path('artisan'), 'foundation:integrity-views'];
    }

    /** @return list<string> 子に付ける -d */
    public static function iniOptions(): array
    {
        return [
            '-d', 'memory_limit='.(string) ini_get('memory_limit'),
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=0',
            '-d', 'html_errors=0',
            '-d', 'log_errors=0',
        ];
    }

    /**
     * @param  array{head: bool, end: bool, bad: bool, count: int, stopped: ?Gap}  $state
     * @param  list<array{0: Finding|Gap, 1: string}>  $results
     * @param  list<string>  $shownDirs
     */
    private function line(string $line, array &$state, array &$results, array $shownDirs): void
    {
        if (! $state['head']) {
            $state['head'] = $line === IntegrityViewsCommand::HEAD;
            $state['bad'] = ! $state['head'];

            return;
        }
        if ($state['end']) {
            $state['bad'] = true;

            return;
        }
        if (preg_match('/\Aend (\d{1,10})\z/', $line, $match) === 1) {
            $state['end'] = true;
            $state['bad'] = (int) $match[1] !== $state['count'];

            return;
        }
        if (preg_match('/\AS ([a-z-]{1,40})\z/', $line, $match) === 1 && in_array($match[1], self::STOPS, true)) {
            $state['stopped'] = Gap::from($match[1]);

            return;
        }
        if (preg_match('/\A([RG]) ([a-z-]{1,40}) ((?:[0-9a-f]{2})+)\z/', $line, $match) !== 1) {
            $state['bad'] = true;

            return;
        }
        $allowed = $match[1] === 'R' ? self::FINDINGS : self::GAPS;
        $path = (string) hex2bin($match[3]);
        $inside = false;
        foreach ($shownDirs as $dir) {
            if ($path === $dir || str_starts_with($path, $dir.'/')) {
                $inside = ! str_contains('/'.$path.'/', '/../');
                break;
            }
        }
        if (! in_array($match[2], $allowed, true) || ! $inside) {
            $state['bad'] = true;

            return;
        }
        $state['count']++;
        $results[] = [$match[1] === 'R' ? Finding::from($match[2]) : Gap::from($match[2]), $path];
    }

    /**
     * 試しの助け：子の命令の前の部分を作る（試しの入り口の PHP の台本を、同じ -d で流す）
     *
     * @return list<string>
     */
    public static function testCommand(string $script): array
    {
        return [PHP_BINARY, ...self::iniOptions(), $script];
    }
}
