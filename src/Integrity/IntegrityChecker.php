<?php

namespace Miraqceed\Foundation\Integrity;

use Closure;
use Illuminate\Support\Facades\Log;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Integrity\Checks\MaintenanceCheck;
use Miraqceed\Foundation\Integrity\Checks\ServedPlacesCheck;
use Miraqceed\Foundation\Integrity\Checks\StoragePhpCheck;
use Miraqceed\Foundation\Integrity\Checks\TreeCheck;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use RuntimeException;
use Throwable;

/**
 * 改ざんの検知の本体。調べの順は 3（メンテナンス）→ 1・2（木と public）→ 4（公開される変わる場所）→ 6（storage の PHP）→ 5（画面。子プロセス）。
 * 1つの期限と網を分け合う。調べごとに受け止め、調べの名前と例外の種類の名前だけを出す（例外の文には絶対の道が入りうる）。
 * 知らせは部品5の AlertSender（鍵は違いの組の指紋。同じ組でも1日1回）。子を使えないときだけ、画面の前に区切りの知らせをする
 *
 * @internal
 */
final class IntegrityChecker
{
    public const ALERT_KEY = 'foundation.integrity:';

    public const VIEWS_ALERT_KEY = 'foundation.integrity.views:';

    /** 知らせの本文の上限（AlertSender::BODY_LIMIT の内） */
    private const BODY_LIMIT = 3900;

    /** FailureReporter の1行に入れる名前の数 */
    private const LOGGED = 20;

    public function __construct(
        private readonly FailureReporter $reporter,
        private readonly AlertSender $alerts,
        private readonly ViewsProcess $views,
        private readonly MaintenanceCheck $maintenance,
    ) {}

    /** @param  (Closure(string): void)|null  $printer  見つけた時に書く出力の1行 */
    public function run(?Closure $printer = null): Report
    {
        $started = microtime(true);
        $report = new Report($printer);
        $root = Context::root();
        $context = new Context($root, Settings::current($root), Deadline::fromConfig(), new MemoryGuard, $report);

        $this->claimPreviousMarks($report);

        $tree = new TreeCheck;
        $this->guarded('maintenance', fn () => $this->maintenance->run($context), $report);
        $this->guarded('tree', fn () => $tree->run($context), $report);
        $this->guarded('served', fn () => (new ServedPlacesCheck)->run($context), $report);
        $this->guarded('storage', fn () => (new StoragePhpCheck)->run($context), $report);

        $mark = null;
        $this->guarded('views', function () use ($context, $tree, $report, $started, &$mark) {
            $excluded = $this->excludedPaths($context);
            $targets = ViewsProcess::targets($context, $excluded);
            if ($targets['dirs'] === []) {
                return;
            }
            if ($this->views->run($context, $targets) === ViewsProcess::CHILD) {
                $report->viewsMode = 'child';

                return;
            }
            // 子を使えない：落ちうるので、画面の前に、それまでの発見を届く形で残す
            $report->viewsMode = 'in-process';
            $mark = ViewsMark::write();
            if ($mark === null) {
                $report->notChecked(Gap::MarkUnwritable);
            }
            $this->tell($report, microtime(true) - $started, 'before-views');
            $before = $report->fingerprint();
            ViewsProcess::inProcess($context, $targets);
            if ($report->fingerprint() !== $before) {
                $report->viewsChanged = true;
            }
        }, $report);

        $phase = $report->viewsMode === 'in-process' ? 'end' : null;
        $this->tell($report, microtime(true) - $started, $phase);
        $mark?->remove();

        return $report;
    }

    /** @param  Closure(): void  $check */
    private function guarded(string $name, Closure $check, Report $report): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            $report->notChecked(Gap::Failed, $name.': '.class_basename($e));
            $this->reporter->report(IntegrityFailure::caught($name, $e));
        }
    }

    /**
     * 画面の元の Blade が、あってはならない場所（storage・調べ1から外した所）
     *
     * @return list<string>
     */
    private function excludedPaths(Context $context): array
    {
        $excluded = $context->current->excludedFromTree($context->snapshotSettings);
        $excluded[] = 'storage';
        $storage = Settings::relative($context->root, storage_path());
        if ($storage !== '' && ! str_starts_with($storage, 'outside:')) {
            $excluded[] = $storage;
        }

        return array_values(array_unique($excluded));
    }

    /** 子を使えないときの、前の回の印（動いていない回のもの）を拾う */
    private function claimPreviousMarks(Report $report): void
    {
        try {
            if (ViewsMark::claimCrashed() > 0) {
                $report->notChecked(Gap::PreviousViewsCrashed);
            }
        } catch (Throwable) {
            // 印を読めなくても、調べは続ける
        }
    }

    /**
     * 知らせ・エラーログの1行・記録の1行。$phase は子を使えないときだけ（before-views・end）
     */
    private function tell(Report $report, float $seconds, ?string $phase): void
    {
        $this->record($report, $seconds, $phase);
        if ($report->isClean()) {
            return;
        }
        if ($phase === 'end' && ! $report->viewsChanged) {
            // 区切りの知らせで届いた。画面の調べに発見がなければ2つ目は送らない
            return;
        }

        try {
            $this->reporter->report(new RuntimeException('改ざんの検知：'.$this->summary($report)));
        } catch (Throwable) {
            // FailureReporter は投げないが、念のため
        }

        $key = ($phase === 'end' ? self::VIEWS_ALERT_KEY : self::ALERT_KEY).$report->fingerprint();
        $this->alerts->send($key, '改ざんの検知：違いが見つかりました', $this->body($report));
    }

    private function record(Report $report, float $seconds, ?string $phase): void
    {
        try {
            $context = [
                'differences' => $report->findingCount(),
                'not_checked' => $report->gapCount(),
                'files' => $report->files,
                'seconds' => round($seconds, 1),
                'views_mode' => $report->viewsMode,
            ];
            if ($phase !== null) {
                $context['phase'] = $phase;
            }
            $channel = config('foundation.integrity.record_channel');
            $logger = is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::getFacadeRoot();
            $logger->log($report->isClean() ? 'info' : 'warning', 'foundation.integrity '.json_encode($context));
        } catch (Throwable) {
            // 記録を書けなくても、知らせは出す
        }
    }

    private function summary(Report $report): string
    {
        $names = [];
        foreach (array_slice($report->findings(), 0, self::LOGGED) as [$finding, $path]) {
            $names[] = "[{$finding->value}] ".Report::display($path);
        }
        $gaps = array_map(fn (array $gap) => $gap[0]->value.($gap[1] === '' ? '' : ' ('.Report::display($gap[1]).')'), array_slice($report->gaps(), 0, self::LOGGED));

        return sprintf('%d difference(s), %d not checked', $report->findingCount(), $report->gapCount())
            .($names === [] ? '' : ': '.implode(', ', $names))
            .($gaps === [] ? '' : '; not checked: '.implode(', ', $gaps));
    }

    private function body(Report $report): string
    {
        $lines = [sprintf('違い %d 件、確かめられなかったところ %d 件。名前だけを出します（中身はサーバーで見てください）。', $report->findingCount(), $report->gapCount())];
        if ($report->viewsMode === 'in-process') {
            $lines[] = '画面の調べは、子プロセスなしで流しました。';
        }
        $lines[] = '';
        foreach ($report->gaps() as [$gap, $detail]) {
            $lines[] = '・確かめられなかった：'.$gap->label().($detail === '' ? '' : '（'.Report::forAlert($detail).'）');
        }
        $shown = 0;
        $length = mb_strlen(implode("\n", $lines));
        foreach ($report->findings() as [$finding, $path]) {
            $line = '・'.$finding->label().'：'.Report::forAlert($path);
            if ($length + mb_strlen($line) + 40 > self::BODY_LIMIT) {
                break;
            }
            $lines[] = $line;
            $length += mb_strlen($line) + 1;
            $shown++;
        }
        $rest = $report->findingCount() - $shown;
        if ($rest > 0) {
            $lines[] = "ほか {$rest} 件";
        }

        return mb_substr(implode("\n", $lines), 0, self::BODY_LIMIT);
    }
}
