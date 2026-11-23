<?php

namespace Miraqceed\Foundation\Integrity\Checks;

use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\ReadThrough;
use Miraqceed\Foundation\Integrity\Settings;

/**
 * 調べ6：storage の中の、PHP として動く名前のファイル（中身は読まない）。外すのは、画面のフォルダーの直下の *.php の
 * ふつうのファイル・storage/framework/maintenance.php・storage_php_allow（storage からの道）だけ。
 * storage は一覧に入れないので、ここに置かれた PHP は、別の書き換え（include を足す）と組で動く。見る所が分かるように名前で出す
 *
 * @internal
 */
final class StoragePhpCheck
{
    public function run(Context $context): void
    {
        $storage = rtrim(str_replace('\\', '/', (string) (realpath(storage_path()) ?: storage_path())), '/');
        if (! is_dir($storage)) {
            return;
        }
        $shownRoot = $context->shown($storage, 'storage');
        $allowed = $context->effective()->storagePhpAllow;

        $compiledDir = rtrim(str_replace('\\', '/', (string) (realpath((string) config('view.compiled')) ?: (string) config('view.compiled'))), '/');

        $reader = new ReadThrough($context->deadline, $context->guard, fn (Gap $gap, string $path) => $context->report->notChecked($gap, $path));
        foreach ($reader->walk($storage, $shownRoot) as $entry) {
            if (! Context::isPhpName($entry->name())) {
                continue;
            }
            $inStorage = substr($entry->absolute, strlen($storage) + 1);
            if ($inStorage === 'framework/maintenance.php' || Settings::within($inStorage, $allowed)) {
                continue;
            }
            if ($compiledDir !== '' && dirname($entry->absolute) === $compiledDir && $entry->type === Entry::FILE && str_ends_with($entry->name(), '.php')) {
                continue;
            }
            $context->report->add(Finding::StoragePhp, $entry->path);
        }
        if ($reader->stopped !== null) {
            $context->report->notChecked($reader->stopped, 'storage');
        }
    }
}
