<?php

namespace Miraqceed\Foundation\Integrity;

use Closure;
use Generator;
use Throwable;

/**
 * 名前を集めない読み流しの歩き（調べ4・6、項目が多すぎるフォルダーの中、画面のフォルダー）。readdir で1つずつ流し、
 * フォルダーでない項目を返す。リンクは辿らない。順は決めない。
 *
 * スタックは段ごとの枠（その段の道を1つと、まだ下りていない下のフォルダーの名前）。開いたままのフォルダーを持たない。
 * 名前の合計は N 件、深さは 256 段まで（超えれば「項目が多すぎる」「木が深すぎる」）。期限と網は項目ごとに見る。
 *
 * @internal
 */
final class ReadThrough
{
    public ?Gap $stopped = null;

    /** @var list<string> 下りなかったフォルダー（表示の道） */
    public array $skipped = [];

    private int $pending = 0;

    /**
     * @param  Closure(Gap, string): void  $onGap
     * @param  int  $depthOffset  表示の道の、最上からの深さ（木の中のフォルダーを読み流すとき）
     * @param  (Closure(string): bool)|null  $skipFolder  下りないフォルダー（表示の道）
     */
    public function __construct(
        private readonly Deadline $deadline,
        private readonly MemoryGuard $guard,
        private readonly Closure $onGap,
        private readonly int $depthOffset = 0,
        private readonly ?Closure $skipFolder = null,
    ) {}

    /**
     * @param  string  $absolute  読み流すフォルダー
     * @param  string  $shown  表示の道（Entry の道の頭）
     * @return Generator<int, Entry>
     */
    public function walk(string $absolute, string $shown): Generator
    {
        /** @var list<array{abs: string, shown: string, depth: int, pending: list<string>}> $stack */
        $stack = [];
        $next = [$absolute, $shown, $this->depthOffset];

        while (true) {
            if ($next !== null) {
                [$abs, $show, $depth] = $next;
                $next = null;
                $folders = [];
                try {
                    $handle = @opendir($abs);
                } catch (Throwable) {
                    $handle = false;
                }
                if ($handle === false) {
                    $this->skipped[] = $show;
                    ($this->onGap)(Gap::Unopened, $show);
                } else {
                    $full = false;
                    try {
                        while (($name = @readdir($handle)) !== false) {
                            if ($name === '.' || $name === '..') {
                                continue;
                            }
                            if ($this->halt()) {
                                return;
                            }
                            $path = $abs.'/'.$name;
                            $entryShown = $show === '' ? $name : $show.'/'.$name;
                            try {
                                $stat = Entry::lstat($path);
                            } catch (Throwable) {
                                $stat = null;
                            }
                            if ($stat === false) {
                                continue;
                            }
                            if ($stat !== null && Entry::typeOf($stat) === 'dir') {
                                if ($this->skipFolder !== null && ($this->skipFolder)($entryShown)) {
                                    continue;
                                }
                                if ($this->pending + count($folders) >= Limits::n()) {
                                    if (! $full) {
                                        $full = true;
                                        $this->skipped[] = $show;
                                        ($this->onGap)(Gap::TooMany, $show);
                                    }

                                    continue;
                                }
                                $folders[] = $name;

                                continue;
                            }
                            yield new Entry($entryShown, $path, Entry::typeOf($stat), $stat, readThrough: true);
                        }
                    } catch (Throwable) {
                        $this->skipped[] = $show;
                        ($this->onGap)(Gap::Unopened, $show);
                    } finally {
                        @closedir($handle);
                    }
                    if ($folders !== []) {
                        if ($depth + 1 > Limits::depth()) {
                            $this->skipped[] = $show;
                            ($this->onGap)(Gap::TooDeep, $show);
                        } else {
                            $this->pending += count($folders);
                            $stack[] = ['abs' => $abs, 'shown' => $show, 'depth' => $depth, 'pending' => $folders];
                        }
                    }
                }
            }

            if ($stack === []) {
                return;
            }
            $top = count($stack) - 1;
            if ($stack[$top]['pending'] === []) {
                array_pop($stack);

                continue;
            }
            $name = array_shift($stack[$top]['pending']);
            $this->pending--;
            $frame = $stack[$top];
            $next = [$frame['abs'].'/'.$name, $frame['shown'] === '' ? $name : $frame['shown'].'/'.$name, $frame['depth'] + 1];
            if ($this->halt()) {
                return;
            }
        }
    }

    private function halt(): bool
    {
        if ($this->stopped !== null) {
            return true;
        }
        if ($this->deadline->passed()) {
            $this->stopped = Gap::TimedOut;
        } elseif ($this->guard->tick()) {
            $this->stopped = Gap::Memory;
        }

        return $this->stopped !== null;
    }
}
