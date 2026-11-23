<?php

namespace Miraqceed\Foundation\Integrity;

use Closure;
use Generator;
use Throwable;

/**
 * 木の歩き（調べ1と、一覧の命令）。PathOrder の順に、フォルダーでない項目を1つずつ返す。リンクは辿らない。
 *
 * - スタックは段ごとの枠（その段の道を1つと、まだ下りていない下のフォルダーの名前）で持つ。
 *   バイト数の上は「N × 255 ＋ 深さ × PATH_MAX」
 * - 1つのフォルダーの名前は N 件まで集める（並べるため）。スタックの名前の合計も N 件、深さは 256 段まで。
 *   超えたフォルダーは比べから外し（skipped）、中は ReadThrough で読み流す（名前と種類だけを見る）
 * - 開けないフォルダーは、その名前を「確かめられなかった」にして続ける
 * - 期限と網は項目ごとに見る。超えたら止める（stopped）
 *
 * @internal
 */
final class Tree
{
    /** @var list<string> 比べから外したフォルダー（その下の一覧の道を「消えた」にしない） */
    public array $skipped = [];

    /** 期限か網で止めた（止めた後の一覧の道を「消えた」にしない） */
    public ?Gap $stopped = null;

    private int $pending = 0;

    /**
     * @param  Closure(string): bool  $excluded  根からの道を外すか（フォルダーにも呼ぶ）
     * @param  Closure(Gap, string): void  $onGap
     */
    public function __construct(
        private readonly string $root,
        private readonly Closure $excluded,
        private readonly Deadline $deadline,
        private readonly MemoryGuard $guard,
        private readonly Closure $onGap,
    ) {}

    /** @return Generator<int, Entry> */
    public function walk(): Generator
    {
        $names = $this->readNames($this->root);
        if ($names === 'memory') {
            $this->stopped = Gap::Memory;

            return;
        }
        if (! is_array($names)) {
            ($this->onGap)($names === 'too-many' ? Gap::TooMany : Gap::Unopened, '.');

            return;
        }
        [$leaves, $folders] = $this->classify('', $names);

        $order = fn (string $name): int => PathOrder::group($name, false);
        $before = array_values(array_filter($folders, fn ($f) => $order($f) < 2));
        $after = array_values(array_filter($folders, fn ($f) => $order($f) > 2));
        usort($before, fn ($a, $b) => [$order($a), $a] <=> [$order($b), $b]);
        usort($after, fn ($a, $b) => $order($a) === $order($b) ? strcmp($a, $b) : $order($a) <=> $order($b));

        foreach ($before as $folder) {
            yield from $this->folder($folder);
            if ($this->stopped !== null) {
                return;
            }
        }
        foreach ($leaves as [$name, $stat]) {
            if ($this->halt()) {
                return;
            }
            yield $this->entry($name, $stat);
        }
        foreach ($after as $folder) {
            yield from $this->folder($folder);
            if ($this->stopped !== null) {
                return;
            }
        }
    }

    /**
     * 1つの最上の段のフォルダーを、明示のスタックで歩く
     *
     * @return Generator<int, Entry>
     */
    private function folder(string $start): Generator
    {
        /** @var list<array{path: string, pending: list<string>}> $stack */
        $stack = [];
        $next = $start;

        while (true) {
            if ($next !== null) {
                $depth = substr_count($next, '/') + 1;
                if ($depth > Limits::depth()) {
                    $this->skip(Gap::TooDeep, $next);
                    yield from $this->readThrough($next);
                } else {
                    $names = $this->readNames($this->root.'/'.$next);
                    if ($names === 'memory') {
                        $this->stopped = Gap::Memory;

                        return;
                    }
                    if ($names === null) {
                        $this->skip(Gap::Unopened, $next);
                    } elseif ($names === 'too-many' || $this->pending + count($names) > Limits::n()) {
                        $this->skip(Gap::TooMany, $next);
                        yield from $this->readThrough($next);
                    } else {
                        [$leaves, $folders] = $this->classify($next, $names);
                        foreach ($leaves as [$path, $stat]) {
                            if ($this->halt()) {
                                return;
                            }
                            yield $this->entry($path, $stat);
                        }
                        if ($folders !== []) {
                            $this->pending += count($folders);
                            $stack[] = ['path' => $next, 'pending' => $folders];
                        }
                    }
                }
                if ($this->stopped !== null) {
                    return;
                }
                $next = null;
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
            $next = $stack[$top]['path'].'/'.$name;
            if ($this->halt()) {
                return;
            }
        }
    }

    /**
     * 名前を並べ、外すものを除き、ファイル・リンクと下のフォルダーに分ける
     *
     * @param  list<string>  $names
     * @return array{0: list<array{0: string, 1: array<int|string, int>|null}>, 1: list<string>}
     */
    private function classify(string $folder, array $names): array
    {
        $leafNames = [];
        $folderNames = [];
        $stats = [];
        foreach ($names as $name) {
            $path = $folder === '' ? $name : $folder.'/'.$name;
            try {
                $stat = Entry::lstat($this->root.'/'.$path);
                if ($stat === false) {
                    continue;
                }
                $isFolder = $stat !== null && Entry::typeOf($stat) === 'dir';
                if (($this->excluded)($path, $isFolder)) {
                    continue;
                }
                if ($isFolder) {
                    $folderNames[] = $name;
                } else {
                    $leafNames[] = $name;
                    $stats[$name] = $stat;
                }
            } catch (Throwable) {
                $leafNames[] = $name;
                $stats[$name] = null;
            }
        }
        [$leafNames, $folderNames] = PathOrder::sortFolder($leafNames, $folderNames);

        $leaves = [];
        foreach ($leafNames as $name) {
            $leaves[] = [$folder === '' ? $name : $folder.'/'.$name, $stats[$name]];
        }

        return [$leaves, $folderNames];
    }

    /** @param  array<int|string, int>|null  $stat */
    private function entry(string $path, ?array $stat): Entry
    {
        return new Entry($path, $this->root.'/'.$path, Entry::typeOf($stat), $stat);
    }

    /** @return Generator<int, Entry> */
    private function readThrough(string $folder): Generator
    {
        $reader = new ReadThrough($this->deadline, $this->guard, $this->onGap, depthOffset: substr_count($folder, '/') + 1);
        foreach ($reader->walk($this->root.'/'.$folder, $folder) as $entry) {
            yield $entry;
        }
        if ($reader->stopped !== null) {
            $this->stopped = $reader->stopped;
        }
    }

    private function skip(Gap $gap, string $path): void
    {
        $this->skipped[] = $path;
        ($this->onGap)($gap, $path);
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

    /**
     * フォルダーの名前を N＋1 件まで集める。開けなければ null、多すぎれば 'too-many'、網を超えれば 'memory'
     *
     * @return list<string>|string|null
     */
    private function readNames(string $absolute): array|string|null
    {
        try {
            $handle = @opendir($absolute);
        } catch (Throwable) {
            $handle = false;
        }
        if ($handle === false) {
            return null;
        }
        $names = [];
        $limit = Limits::n();
        try {
            while (($name = @readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (count($names) >= $limit) {
                    return 'too-many';
                }
                if ($this->guard->tick()) {
                    return 'memory';
                }
                $names[] = $name;
            }
        } catch (Throwable) {
            return null;
        } finally {
            @closedir($handle);
        }

        return $names;
    }
}
