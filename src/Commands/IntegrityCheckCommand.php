<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\Integrity\IntegrityChecker;

/**
 * 改ざんの検知：木の全体をハッシュの一覧と比べ、public の PHP・公開される変わる場所・メンテナンスの入口・コンパイルした画面・
 * storage の PHP を見る。違いと確かめられなかったところを1行ずつ出し（見つけた時に）、あれば部品5の知らせで送る。
 * すべて確かめて違いがなければ 0、ほかは 1。schedule に入れるのはアプリ（Integrity::schedule()）
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'foundation:integrity-check';

    protected $description = '改ざんの検知：ファイルの違いを調べ、名前だけを知らせる';

    public function handle(IntegrityChecker $checker): int
    {
        $report = $checker->run(fn (string $line) => $this->line($line));

        if ($report->isClean()) {
            $this->info('すべて確かめました。違いはありません。');

            return self::SUCCESS;
        }

        foreach ($report->findingCounts() as $kind => $count) {
            $this->line("  {$kind}: {$count}");
        }
        $this->error(sprintf('違い %d 件、確かめられなかったところ %d 件。名前だけを出しました（docs/integrity.md の「違いが出たとき」）。', $report->findingCount(), $report->gapCount()));

        return self::FAILURE;
    }
}
