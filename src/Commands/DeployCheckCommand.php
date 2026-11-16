<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\DeployCheck\Runner;
use Miraqceed\Foundation\DeployCheck\Status;

/**
 * 本番の設定を1か所で調べる。違う所があれば終わりの値 1（配置の台本の最後・CI で使う）。自動では流れない。
 * 行の形（[状態] id 説明）と id は、CI の台本が読むので変えない。秘密の値は出さない
 */
class DeployCheckCommand extends Command
{
    /** JSON の形の版。形を変えたら上げる */
    public const SCHEMA_VERSION = 1;

    protected $signature = 'foundation:deploy-check
        {--json : 結果を JSON で出す}
        {--strict : later（まだ要らない）も失敗とみなす}';

    protected $description = '本番の設定を調べる（違う所があれば 1。docs/deploy-check.md）';

    public function handle(Runner $runner): int
    {
        $rows = $runner->run();
        $strict = (bool) $this->option('strict');

        $failed = count(array_filter($rows, fn (array $row) => $row['status'] === Status::Fail));
        $later = count(array_filter($rows, fn (array $row) => $row['status'] === Status::Later));
        $passed = $failed === 0 && (! $strict || $later === 0);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'passed' => $passed,
                'checks' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'status' => $row['status']->value,
                    'label' => $row['label'],
                    'detail' => $row['detail'],
                ], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($rows as $row) {
            $line = str_pad('['.$row['status']->mark().']', 8).str_pad($row['id'], 24).' '.$row['label'];
            if ($row['detail'] !== null && $row['detail'] !== '') {
                $line .= ': '.$row['detail'];
            }
            $this->line($line);
        }

        $summary = "設定の点検: 失敗 {$failed}";
        if ($strict && $later > 0) {
            $summary .= "・later {$later}（--strict では失敗とみなす）";
        }
        if ($passed) {
            $this->info($summary);
        } else {
            $this->error($summary.'（docs/deploy-check.md）');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
