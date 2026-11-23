<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\Integrity\Checks\TreeCheck;
use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Fingerprint;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\MemoryGuard;
use Miraqceed\Foundation\Integrity\Report;
use Miraqceed\Foundation\Integrity\Settings;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\SnapshotWriter;
use Miraqceed\Foundation\Integrity\Tree;
use Throwable;

/**
 * 改ざんの検知のハッシュの一覧を作り直す。作り直す前に、必ず foundation:integrity-check で 0 を確かめる
 * （作業の前に書き換えられたものを、正しいものとして入れないため。前の一覧とは比べない）。
 * 読めないファイル・ふつうのファイルでないもの・開けないフォルダー・項目が多すぎるフォルダー・深すぎる木・期限・公開ディレクトリが
 * 木の外、のどれかがあれば、その名前を出して一覧を書かない（穴のある一覧は、後で置かれたものを通す）
 */
class IntegritySnapshotCommand extends Command
{
    protected $signature = 'foundation:integrity-snapshot';

    protected $description = '改ざんの検知のハッシュの一覧を作り直す（作り直す前に foundation:integrity-check で確かめる）';

    public function handle(): int
    {
        $root = Context::root();
        $settings = Settings::current($root);
        $report = new Report(fn (string $line) => $this->line($line));
        $context = new Context($root, $settings, Deadline::fromConfig(), new MemoryGuard, $report);

        if (TreeCheck::publicOutside($root)) {
            $report->notChecked(Gap::PublicOutside);
        }

        $writer = null;
        $problems = 0;
        try {
            $writer = Snapshot::writer($root, $settings);
            $tree = new Tree($root, (new TreeCheck)->excluder($context), $context->deadline, $context->guard,
                function (Gap $gap, string $path) use ($report, &$problems) {
                    $problems++;
                    $report->notChecked($gap, $path);
                });
            foreach ($tree->walk() as $entry) {
                if ($entry->readThrough) {
                    continue;
                }
                $problem = $this->add($writer, $entry, $context);
                if ($problem !== null) {
                    $problems++;
                    $this->error("[{$problem}] ".Report::display($entry->path));
                }
                if ($context->deadline->passed()) {
                    break;
                }
            }
            if ($tree->stopped !== null || $context->deadline->passed()) {
                $problems++;
                $report->notChecked($tree->stopped ?? Gap::TimedOut, 'snapshot');
            }
            if ($report->hasGap(Gap::PublicOutside)) {
                $problems++;
            }
            if ($problems > 0) {
                Snapshot::discard($writer);
                $this->error('一覧を書きませんでした（上の名前を確かめてから、もう一度流してください）');

                return self::FAILURE;
            }
            $writer->commit();
        } catch (Throwable $e) {
            Snapshot::discard($writer);
            $this->error('一覧を書けませんでした（'.$e::class.'）');

            return self::FAILURE;
        }

        $this->info(sprintf('一覧に %d 件を書きました。', $writer->count()));

        return self::SUCCESS;
    }

    /** 1つの項目を書く。書けなければ理由の印 */
    private function add(SnapshotWriter $writer, Entry $entry, Context $context): ?string
    {
        if ($entry->type === Entry::LINK) {
            $target = Fingerprint::link($entry);
            if ($target === null) {
                return 'unreadable';
            }
            $writer->link($entry->path, $target);

            return null;
        }
        if ($entry->type === Entry::OTHER) {
            return 'not-regular';
        }
        if ($entry->type !== Entry::FILE) {
            return 'unreadable';
        }
        $hash = Fingerprint::file($entry, $context->deadline);
        if ($hash === false) {
            return 'timed-out';
        }
        if ($hash === null) {
            return 'unreadable';
        }
        $writer->file($entry->path, $hash);

        return null;
    }
}
