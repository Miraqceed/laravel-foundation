<?php

namespace Miraqceed\Foundation\Integrity;

use RuntimeException;
use Throwable;

/**
 * ハッシュの一覧（1行に1項目）。丸ごと解かずに1行ずつ読み書きする（解くと大きさの何倍ものメモリーを使い、尽きると受け止められない）。
 *
 *   foundation-integrity 1 {"root":…,"created_at":…,"settings":{…}}   ← 頭の行（64KB まで）
 *   F <道の16進> <sha256の16進>                                         ← ふつうのファイル（1行 20KB まで）
 *   L <道の16進> <先の16進>                                             ← リンク
 *   end <件数> <項目の行の sha256>                                       ← 終わりの行
 *
 * 項目は PathOrder の順に厳しく増える。終わりの指紋は鍵のない sha256 なので、偽造には効かない（切られた・壊れた・
 * ほかの行と合わない一覧を見分けるためのもの）
 *
 * @internal
 */
final class Snapshot
{
    public const MAGIC = 'foundation-integrity';

    public const VERSION = 1;

    public const TEMPORARY_PREFIX = '.tmp-';

    public static function path(): string
    {
        $path = config('foundation.integrity.snapshot');

        return is_string($path) && $path !== '' ? $path : storage_path('app/foundation/integrity/snapshot.lines');
    }

    public static function rootFingerprint(string $root): string
    {
        return hash('sha256', $root);
    }

    /** 書き始める（同じディレクトリの一時のファイル） */
    public static function writer(string $root, Settings $settings): SnapshotWriter
    {
        return new SnapshotWriter(self::path(), [
            'root' => self::rootFingerprint($root),
            'created_at' => now()->toIso8601String(),
            'settings' => $settings->toArray(),
        ]);
    }

    /**
     * 読み始める。頭の行が読めなければ、理由の Gap を投げる（SnapshotUnavailable）
     */
    public static function reader(string $root): SnapshotReader
    {
        return SnapshotReader::open(self::path(), self::rootFingerprint($root));
    }

    /** 点検のために、頭の行と最後の行だけを見る。問題がなければ null、あれば理由の Gap */
    public static function quickCheck(string $root): ?Gap
    {
        try {
            $reader = self::reader($root);
        } catch (SnapshotUnavailable $e) {
            return $e->gap;
        }

        return $reader->tailLooksComplete() ? null : Gap::BrokenSnapshot;
    }

    /** 書いている途中で投げても、一時のファイルを残さない */
    public static function discard(?SnapshotWriter $writer): void
    {
        try {
            $writer?->abort();
        } catch (Throwable) {
            // 片付けの失敗で、本筋の失敗を隠さない
        }
    }

    public static function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('一覧の置き場所のディレクトリを作れません');
        }
    }
}
