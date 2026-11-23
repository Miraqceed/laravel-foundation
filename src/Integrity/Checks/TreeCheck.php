<?php

namespace Miraqceed\Foundation\Integrity\Checks;

use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Fingerprint;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\GitPaths;
use Miraqceed\Foundation\Integrity\PathOrder;
use Miraqceed\Foundation\Integrity\Settings;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\SnapshotReader;
use Miraqceed\Foundation\Integrity\SnapshotUnavailable;
use Miraqceed\Foundation\Integrity\Tree;

/**
 * 調べ1（木の全体を一覧と比べる）と調べ2（public の PHP）。同じ歩きで見る。一覧は1行ずつ読み、歩きと突き合わせる。
 * 一覧が使えないときも歩き、2 は出す
 *
 * @internal
 */
final class TreeCheck
{
    private const SAME = 0;

    private const CHANGED = 1;

    private const TIMED_OUT = 2;

    public function run(Context $context): void
    {
        $report = $context->report;

        $reader = null;
        try {
            $reader = Snapshot::reader($context->root);
            $context->snapshotSettings = $reader->settings;
            $context->snapshotUsable = true;
        } catch (SnapshotUnavailable $e) {
            $report->notChecked($e->gap);
        }

        $this->checkSettings($context);

        $excluded = $this->excluder($context);
        $tree = new Tree($context->root, $excluded, $context->deadline, $context->guard, fn (Gap $gap, string $path) => $report->notChecked($gap, $path));
        $publicPhp = $context->effective()->publicPhp;

        $listed = $reader?->next();
        $compared = 0;
        $brokenTold = false;
        $stoppedByHash = null;

        foreach ($tree->walk() as $entry) {
            $this->publicChecks($entry, $publicPhp, $report);

            if ($entry->readThrough) {
                continue;
            }
            if ($entry->type === Entry::OTHER) {
                $report->add(Finding::NotRegular, $entry->path);
            } elseif ($entry->type === Entry::UNREADABLE) {
                $report->add(Finding::Unreadable, $entry->path);
            }

            if ($reader === null) {
                continue;
            }
            // 一覧の、この項目より前の道は「消えた」（比べから外したフォルダーの下は除く）
            while ($listed !== null && PathOrder::compare($listed[0], $entry->path) < 0) {
                if (! Settings::within($listed[0], $tree->skipped)) {
                    $report->add(Finding::Removed, $listed[0]);
                }
                $listed = $reader->next();
            }
            if ($reader->broken && ! $brokenTold) {
                $brokenTold = true;
                $report->notChecked(Gap::BrokenSnapshot);
            }

            if ($listed !== null && $listed[0] === $entry->path) {
                $compared++;
                $result = $this->differs($entry, $listed, $context);
                if ($result === self::TIMED_OUT) {
                    $stoppedByHash = Gap::TimedOut;
                    break;
                }
                if ($result === self::CHANGED) {
                    $report->add(Finding::Changed, $entry->path);
                }
                $listed = $reader->next();
            } elseif ($entry->type === Entry::FILE || $entry->type === Entry::LINK) {
                $report->add(Finding::Added, $entry->path);
            }
            if ($reader->broken && ! $brokenTold) {
                $brokenTold = true;
                $report->notChecked(Gap::BrokenSnapshot);
            }
        }

        $stopped = $tree->stopped ?? $stoppedByHash;
        if ($stopped !== null) {
            $report->notChecked($stopped, 'tree');
        }

        if ($reader !== null) {
            if ($stopped === null) {
                // 歩き終えた：一覧の残りは「消えた」
                while ($listed !== null) {
                    if (! Settings::within($listed[0], $tree->skipped)) {
                        $report->add(Finding::Removed, $listed[0]);
                    }
                    $listed = $reader->next();
                }
                if ($reader->broken && ! $brokenTold) {
                    $report->notChecked(Gap::BrokenSnapshot);
                }
            } else {
                $report->notChecked(Gap::SnapshotUnfinished);
                $reader->close();
            }
            $report->files = $compared;
        }
    }

    /**
     * 外すもの：storage（4・5・6 で見る）・.git の中の設定と hook のほか・exclude と acme・served（一覧と今の値の両方にあるものだけ）
     *
     * @return \Closure(string, bool=): bool
     */
    public function excluder(Context $context): \Closure
    {
        $excluded = $context->current->excludedFromTree($context->snapshotSettings);
        $storage = Settings::relative($context->root, storage_path());
        if (! str_starts_with($storage, 'outside:') && $storage !== '') {
            $excluded[] = $storage;
        }
        $excluded[] = 'storage';
        // 木の中の SQLite のファイル（書くたびに変わる）。ふつうのファイルのときだけ外す（リンク・フォルダーなら比べる）
        $sqlite = $context->current->sqliteExcluded($context->snapshotSettings);

        return fn (string $path, bool $isFolder = false, ?string $type = null): bool => Settings::within($path, $excluded)
            || ($type === Entry::FILE && in_array($path, $sqlite, true))
            || GitPaths::excluded($path, $isFolder);
    }

    /** 調べ2：public の中の PHP として動く名前（一覧を作ったときからあったものも）。読み流した所では、リンクとふつうでないものも */
    private function publicChecks(Entry $entry, array $allowed, $report): void
    {
        if (! str_starts_with($entry->path, 'public/')) {
            if ($entry->readThrough && $entry->type === Entry::OTHER) {
                $report->add(Finding::NotRegular, $entry->path);
            }

            return;
        }
        if (Context::isPhpName($entry->name()) && ! in_array($entry->path, $allowed, true)) {
            $report->add(Finding::PublicPhp, $entry->path);
        }
        if ($entry->readThrough) {
            if ($entry->type === Entry::LINK) {
                $report->add(Finding::ServedLink, $entry->path);
            } elseif ($entry->type === Entry::OTHER) {
                $report->add(Finding::NotRegular, $entry->path);
            } elseif ($entry->isHardLinked()) {
                $report->add(Finding::ServedHardLink, $entry->path);
            }
        }
    }

    /**
     * 一覧の指紋と比べる（SAME・CHANGED・TIMED_OUT）
     *
     * @param  array{0: string, 1: string, 2: string}  $listed
     */
    private function differs(Entry $entry, array $listed, Context $context): int
    {
        [, $type, $value] = $listed;
        if ($entry->type === Entry::LINK) {
            return $type !== 'L' || Fingerprint::link($entry) !== $value ? self::CHANGED : self::SAME;
        }
        if ($entry->type !== Entry::FILE) {
            // ふつうでない・読めないものは、上で知らせた
            return self::SAME;
        }
        if ($type !== 'F') {
            return self::CHANGED;
        }
        $hash = Fingerprint::file($entry, $context->deadline);
        if ($hash === false) {
            return self::TIMED_OUT;
        }
        if ($hash === null) {
            $context->report->add(Finding::Unreadable, $entry->path);

            return self::SAME;
        }

        return hash_equals($value, $hash) ? self::SAME : self::CHANGED;
    }

    private function checkSettings(Context $context): void
    {
        $report = $context->report;
        if ($context->snapshotSettings !== null && ! $context->snapshotSettings->sameAs($context->current)) {
            $report->notChecked(Gap::SettingsDiffer);
        }
        $covering = $context->current->coveringLoadPaths();
        if ($context->snapshotSettings !== null) {
            $covering = [...$covering, ...$context->snapshotSettings->coveringLoadPaths()];
        }
        if ($covering !== []) {
            $report->notChecked(Gap::ExcludesLoadPaths, implode(', ', array_unique($covering)));
        }
        if ($context->current->refused['generated_views'] !== []) {
            $report->notChecked(Gap::GeneratedViewsRefused, implode(', ', $context->current->refused['generated_views']));
        }
        if ($context->current->refused['sqlite'] !== []) {
            $report->notChecked(Gap::SqliteNotExcluded, implode(', ', $context->current->refused['sqlite']));
        }
        if (self::publicOutside($context->root)) {
            $report->notChecked(Gap::PublicOutside);
        }
    }

    /** 公開ディレクトリが木の public でない（usePublicPath() で木の外・木の public がリンク） */
    public static function publicOutside(string $root): bool
    {
        if (is_link($root.'/public')) {
            return true;
        }
        $public = realpath(app()->publicPath());
        $own = realpath($root.'/public');

        return $public !== false && $own !== false ? $public !== $own : $public !== false && $own === false;
    }
}
