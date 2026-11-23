<?php

namespace Miraqceed\Foundation\Integrity\Checks;

use Illuminate\Foundation\Console\DownCommand;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use ReflectionClass;
use Throwable;

/**
 * 調べ3：メンテナンスの入口（storage/framework/maintenance.php）と設定（storage/framework/down）。
 * public/index.php は、入口があればオートローダーより前に require する。artisan down は Laravel の雛形をそのまま写す。
 * down の template はすべての人に出され、redirect はすべての人を送る。値は出さない（secret はメンテナンスを抜ける合言葉）。
 * ふつうのファイルでなければ読まない（名前つきのパイプを読むと止まる）。大きさの上限を超えれば読まない
 *
 * @internal
 */
class MaintenanceCheck
{
    /** @param  string|null  $stub  比べる雛形（null なら読み込んだ Laravel のもの。試しで替える） */
    public function __construct(private readonly ?string $stub = null) {}

    public function run(Context $context): void
    {
        $report = $context->report;
        $entry = storage_path('framework/maintenance.php');
        $entryShown = $context->shown($entry, 'storage/framework/maintenance.php');

        $stat = Entry::lstat($entry);
        if ($stat !== false) {
            $stub = $this->stub();
            $stubStat = is_file($stub) ? @stat($stub) : false;
            if ($stat === null || Entry::typeOf($stat) !== Entry::FILE) {
                $report->add(Finding::MaintenanceEntry, $entryShown);
            } elseif ($stubStat === false) {
                $report->notChecked(Gap::NoStub);
            } elseif ($stat['size'] !== $stubStat['size']) {
                // 大きさが違えば読まない
                $report->add(Finding::MaintenanceEntry, $entryShown);
            } elseif (! hash_equals((string) @hash_file('sha256', $stub), (string) $this->hash($entry, $stat, $stubStat['size']))) {
                $report->add(Finding::MaintenanceEntry, $entryShown);
            }
        }

        $down = storage_path('framework/down');
        $downShown = $context->shown($down, 'storage/framework/down');
        $stat = Entry::lstat($down);
        if ($stat === false) {
            return;
        }
        // 調べの時刻に更新はしない決まり：止まった更新か、置かれた down（schedule のほかの処理は止まっている）
        $report->add(Finding::InMaintenance, $downShown);
        if ($stat === null || Entry::typeOf($stat) !== Entry::FILE) {
            $report->add(Finding::MaintenanceData, $downShown);

            return;
        }
        $limit = Limits::downBytes();
        if ($stat['size'] > $limit || ! $context->guard->fits($stat['size'] * 16)) {
            $report->add(Finding::MaintenanceData, $downShown);

            return;
        }
        $contents = $this->read($down, $stat, $limit);
        $data = $contents === null ? null : json_decode($contents, true, 32);
        if (! is_array($data) || ! $this->allowed($data, $context->effective()->maintenanceAllow)) {
            $report->add(Finding::MaintenanceData, $downShown);
        }
    }

    /** @param  array<mixed>  $data */
    private function allowed(array $data, array $allow): bool
    {
        foreach (['template', 'redirect', 'secret', 'refresh'] as $key) {
            if (($data[$key] ?? null) !== null && ! in_array($key, $allow, true)) {
                return false;
            }
        }
        $except = $data['except'] ?? [];
        if (! is_array($except)) {
            return false;
        }
        $excluded = $this->excludedPaths();
        foreach ($except as $path) {
            if (! is_string($path) || ! in_array($path, $excluded, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * artisan down が except に書くもの（DownCommand と同じ順で読む）
     *
     * @return list<string>
     */
    private function excludedPaths(): array
    {
        foreach (['App\\Http\\Middleware\\PreventRequestsDuringMaintenance', PreventRequestsDuringMaintenance::class] as $class) {
            try {
                if (class_exists($class)) {
                    return array_values(array_map('strval', app($class)->getExcludedPaths()));
                }
            } catch (Throwable) {
                continue;
            }
        }

        return [];
    }

    private function stub(): string
    {
        if ($this->stub !== null) {
            return $this->stub;
        }

        return dirname((string) (new ReflectionClass(DownCommand::class))->getFileName()).'/stubs/maintenance-mode.stub';
    }

    /** @param  array<int|string, int>  $stat */
    private function hash(string $path, array $stat, int $limit): ?string
    {
        $contents = $this->read($path, $stat, $limit);

        return $contents === null ? null : hash('sha256', $contents);
    }

    /**
     * 開いた後に fstat で、同じふつうのファイルかを確かめ、上限＋1 バイトまでしか読まない
     *
     * @param  array<int|string, int>  $stat
     */
    private function read(string $path, array $stat, int $limit): ?string
    {
        try {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            $opened = @fstat($handle);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $stat['ino'] || $opened['size'] > $limit) {
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
