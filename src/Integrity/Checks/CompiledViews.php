<?php

namespace Miraqceed\Foundation\Integrity\Checks;

use Closure;
use Illuminate\View\Compilers\BladeCompiler;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\MemoryGuard;
use Miraqceed\Foundation\Integrity\ReadThrough;
use Miraqceed\Foundation\Integrity\Settings;
use Throwable;

/**
 * 調べ5：コンパイルした画面が、元の Blade と同じか。画面の調べの子プロセスの中で流す（子を使えないときは親の中で、控えめに）。
 *
 * - appendFilePath()・compile() を呼ばない（出力の全体を token_get_all すると、元の数百〜数千倍のメモリーを使う）。
 *   compileString() の出力と、書いたものから終わりの PATH の行を外したものを比べ、出力か、出力＋ " ?>" なら通す
 * - 名前は、普通の echo とメールの echo（usingEchoFormat() があるときだけ）の両方の getCompiledPath() で比べる
 * - 元の Blade は、渡された画面の場所の中で、木の中（storage・外したものの外）にあること
 * - 許す違いは、@once などが作る UUID の形の字だけ
 * - 大きさの上の上限と、大きさと広がる印の数の見積もりで止める（超えた画面は名前を出して「確かめられなかった」）
 * - フォルダーの、直下の *.php のふつうのファイルでないもの（下のフォルダー・リンク・ほかの名前）も名前で出す
 * - アプリが generated_views で許した所（部品が作る画面のフォルダー・Blade::render() が置く元）は見ない。
 *   許した元から作った画面は、その元とコンパイルし直して比べる
 *
 * @internal
 */
final class CompiledViews
{
    /** Illuminate\Mail\Markdown::render() が使う echo の形 */
    public const MAIL_ECHO_FORMAT = 'new \Illuminate\Support\EncodedHtmlString(%s)';

    private const SOURCE_PATH = '/<\?php \/\*\*PATH ([^\n]+) ENDPATH\*\*\/ \?>\z/';

    private const ONCE_ID = "/'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'/";

    /** Illuminate\View\Component::createBladeViewFromString() が画面のフォルダーに置く元の名前（xxh128 の16進） */
    public const INLINE_SOURCE = '/\A[0-9a-f]{32}\.blade\.php\z/';

    /**
     * @param  list<string>  $sourceDirs  元の Blade があってよい場所（絶対の道。木の中のもの）
     * @param  list<string>  $excluded  木の中で外す道（根からの道）
     * @param  float  $ratio  見積もりと比べる、網の残りの割合（子は 0.5、親の中は 0.1）
     * @param  Closure(Finding|Gap, string): void  $emit
     * @param  int  $maxMarks  1つの元の、広がる印の数の上限（子は多め、親の中は少なめ）
     * @param  list<string>  $generatedFolders  アプリが許した、部品が作る画面のフォルダー（表示の道。画面のフォルダーの下だけ）。見ない
     * @param  list<string>  $inlineDirs  Blade::render()・インラインの部品が置く元（<hash>.blade.php）を許す画面のフォルダー（表示の道）
     * @param  (Closure(string): void)|null  $onStart  画面を1つ確かめ始める前に呼ぶ（子は「始める」の行を書く）
     * @param  list<string>  $skip  確かめない画面（表示の道。始め直した子で、前の子が済ませた・落ちた画面）
     */
    public function __construct(
        private readonly string $root,
        private readonly array $sourceDirs,
        private readonly array $excluded,
        private readonly Deadline $deadline,
        private readonly MemoryGuard $guard,
        private readonly float $ratio,
        private readonly Closure $emit,
        private readonly int $maxMarks = Limits::VIEW_MARKS_CHILD,
        private readonly array $generatedFolders = [],
        private readonly array $inlineDirs = [],
        private readonly ?Closure $onStart = null,
        array $skip = [],
    ) {
        $this->skip = array_fill_keys($skip, true);
    }

    /** @var array<string, true> */
    private readonly array $skip;

    /** いま見ている画面のフォルダー（絶対の道）。インラインの元を許すときだけ */
    private ?string $inlineDirectory = null;

    /**
     * @param  string  $directory  画面のフォルダー（絶対の道）
     * @param  string  $shown  表示の道
     */
    public function check(string $directory, string $shown): ?Gap
    {
        if (! is_dir($directory)) {
            return null;
        }
        $compiler = app('blade.compiler');
        $inline = in_array($shown, $this->inlineDirs, true);
        $this->inlineDirectory = $inline ? rtrim(str_replace('\\', '/', (string) (realpath($directory) ?: $directory)), '/') : null;
        // アプリが許した、部品が作る画面のフォルダーには下りない（そこに置かれた PHP は見つけられない。説明書の限界）
        $reader = new ReadThrough($this->deadline, $this->guard, fn (Gap $gap, string $path) => ($this->emit)($gap, $path),
            skipFolder: fn (string $folder): bool => Settings::within($folder, $this->generatedFolders));
        foreach ($reader->walk($directory, $shown) as $entry) {
            $direct = $entry->path === $shown.'/'.$entry->name();
            // Blade::render()・インラインの部品が置く元。中身は確かめられない（その画面は、この元とコンパイルし直して比べる）
            if ($inline && $direct && $entry->type === Entry::FILE && preg_match(self::INLINE_SOURCE, $entry->name()) === 1) {
                continue;
            }
            if (! $direct || $entry->type !== Entry::FILE || ! str_ends_with($entry->name(), '.php')) {
                if (! ($direct && $entry->name() === '.gitignore' && $entry->type === Entry::FILE)) {
                    ($this->emit)(Finding::ViewStray, $entry->path);
                }

                continue;
            }
            if (isset($this->skip[$entry->path])) {
                continue;
            }
            if ($this->onStart !== null) {
                ($this->onStart)($entry->path);
            }
            try {
                $finding = $this->verify($compiler, $entry);
            } catch (Throwable) {
                $finding = Finding::ViewContent;
            }
            if ($finding !== null) {
                ($this->emit)($finding, $entry->path);
            }
        }

        return $reader->stopped;
    }

    private function verify(BladeCompiler $compiler, Entry $entry): Finding|Gap|null
    {
        $size = (int) ($entry->stat['size'] ?? 0);
        if ($size > Limits::VIEW_COMPILED_BYTES || ! $this->guard->fits($size * 4, $this->ratio)) {
            ($this->emit)(Gap::ViewTooLarge, $entry->path);

            return null;
        }
        $compiled = $this->read($entry->absolute, $entry->stat, Limits::VIEW_COMPILED_BYTES);
        if ($compiled === null) {
            return Finding::Unreadable;
        }
        if (preg_match(self::SOURCE_PATH, $compiled, $match) !== 1) {
            return Finding::ViewWithoutSource;
        }
        $source = $match[1];
        $rest = substr($compiled, 0, -strlen($match[0]));
        unset($compiled);

        $real = realpath($source);
        if ($real === false || ! is_file($real) || ! $this->allowedSource($real)) {
            return Finding::ViewWithoutSource;
        }

        // Laravel は、末尾に書いた道（解決する前の道）で名前を付ける
        if (! in_array($entry->name(), $this->names($compiler, $source), true)) {
            return Finding::ViewName;
        }

        $sourceStat = Entry::lstat($real);
        if (! is_array($sourceStat) || $sourceStat['size'] > Limits::VIEW_SOURCE_BYTES) {
            ($this->emit)(Gap::ViewTooLarge, $entry->path);

            return null;
        }
        $blade = $this->read($real, $sourceStat, Limits::VIEW_SOURCE_BYTES);
        if ($blade === null) {
            return Finding::ViewWithoutSource;
        }
        // 広がる印の数は、メモリーのほかに時間も決める（@props を並べた元は、印の数の2乗ほどの時間が掛かる。compileString() の
        // 中は期限を見られない）。子は期限で止められるので多め、親の中は少なめ
        $marks = Limits::viewMarks($blade);
        $estimate = Limits::viewEstimate(strlen($blade), $marks, Limits::viewComponents($blade));
        if ($marks > $this->maxMarks || ! $this->guard->fits($estimate, $this->ratio)) {
            ($this->emit)(Gap::ViewTooLarge, $entry->path);

            return null;
        }

        $rest = $this->withoutOnceIds($rest);
        if ($this->matches($rest, $this->compileString($compiler, $source, $blade))) {
            return null;
        }
        if (method_exists($compiler, 'usingEchoFormat')) {
            $asMail = $compiler->usingEchoFormat(self::MAIL_ECHO_FORMAT, fn (): string => $this->compileString($compiler, $source, $blade));
            if ($this->matches($rest, $asMail)) {
                return null;
            }
        }

        return Finding::ViewContent;
    }

    /** @return list<string> */
    private function names(BladeCompiler $compiler, string $source): array
    {
        $names = [basename($compiler->getCompiledPath($source))];
        if (method_exists($compiler, 'usingEchoFormat')) {
            $names[] = basename($compiler->usingEchoFormat(self::MAIL_ECHO_FORMAT, fn () => $compiler->getCompiledPath($source)));
        }

        return array_values(array_unique($names));
    }

    private function matches(string $rest, string $compiled): bool
    {
        $compiled = $this->withoutOnceIds($compiled);

        return $rest === $compiled || $rest === $compiled.' ?>';
    }

    private function compileString(BladeCompiler $compiler, string $path, string $blade): string
    {
        $compiler->setPath($path);

        return $compiler->compileString($blade);
    }

    private function withoutOnceIds(string $compiled): string
    {
        return (string) preg_replace(self::ONCE_ID, "'once'", $compiled);
    }

    private function allowedSource(string $real): bool
    {
        $real = str_replace('\\', '/', $real);
        if ($this->inlineDirectory !== null && dirname($real) === $this->inlineDirectory && preg_match(self::INLINE_SOURCE, basename($real)) === 1) {
            return true;
        }
        $relative = Settings::relative($this->root, $real);
        // 許したフォルダーの中の元（Livewire 4 の部品の画面）。中身は確かめられないが、その画面は元とコンパイルし直して比べる
        if (Settings::within($relative, $this->generatedFolders)) {
            return true;
        }
        if (str_starts_with($relative, 'outside:') || Settings::within($relative, $this->excluded)) {
            return false;
        }
        foreach ($this->sourceDirs as $dir) {
            if (str_starts_with($real, rtrim($dir, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int|string, int>|null  $stat */
    private function read(string $path, ?array $stat, int $limit): ?string
    {
        try {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            $opened = @fstat($handle);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || ($stat !== null && $opened['ino'] !== $stat['ino']) || $opened['size'] > $limit) {
                fclose($handle);

                return null;
            }
            $contents = (string) @stream_get_contents($handle, $limit + 1);
            fclose($handle);

            return strlen($contents) > $limit ? null : $contents;
        } catch (Throwable) {
            return null;
        }
    }
}
