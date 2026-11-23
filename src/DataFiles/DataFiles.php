<?php

namespace Miraqceed\Foundation\DataFiles;

use Closure;
use Miraqceed\Foundation\Failures\FailureReporter;
use RuntimeException;
use Throwable;

/**
 * 読む側の助け（容器の singleton。1つのプロセスで覚えたものを使い回す）。
 *
 * - path()：置き場所のファイル、なければ種類の写し（fallbackPath()）、なければ null。
 *   null と写しのときは知らせる（鍵ごとに1時間に1回、プロセスをまたいで。local・testing の環境では黙る）
 * - changeKey()：道・inode・mtime・大きさ。入れ替えると変わる（時刻を保った写し rsync -t でも、rename なら inode が変わる）
 * - remember()：解いた結果を、同じプロセスでは1回だけ解き、プロセスをまたいでは serialize したファイルに置いて読む
 *   （PHP-FPM は要求をまたいで static を持たない）。unserialize は allowed_classes を必ず絞る。PHP のファイルにしない
 */
final class DataFiles
{
    /** 「データがない・写しを読んでいる」の知らせの窓（秒） */
    public const MISSING_REPORT_SECONDS = 3600;

    /** @var array<string, array{key: string, value: mixed}> */
    private array $remembered = [];

    public function __construct(
        private readonly Sources $sources,
        private readonly FailureReporter $reporter = new FailureReporter,
    ) {}

    /** @throws \LogicException 登録していない名前のとき */
    public function path(string $name): ?string
    {
        // 頼まれた種類だけを引く（作った種類はこの入れ物で覚える。ほかの種類の書き違いで止まらない）
        $source = $this->sources->find($name);
        $store = Store::for($name, $this->reporter);
        if ($store->hasData()) {
            return $store->dataPath();
        }

        $fallback = $source->fallbackPath();
        if (is_string($fallback) && $fallback !== '' && is_file($fallback)) {
            $this->reportMissing($name, "データのファイル {$name} が置き場所にありません。写しを読んでいます（php artisan foundation:data-files）");

            return $fallback;
        }

        $this->reportMissing($name, "データのファイル {$name} がありません。これを使う判定は効いていません（php artisan foundation:data-files）");

        return null;
    }

    /** 道・inode・mtime・大きさ。ファイルがなければ null */
    public function changeKey(string $name): ?string
    {
        $path = $this->path($name);

        return $path === null ? null : self::keyOf($path);
    }

    /**
     * 解いた結果を返す。ファイルがなければ null（path() と同じく知らせる）。
     * 読めない・壊れた・許していない物を含む写しは捨てて解き直す。置けなくても、解いた値を返す
     *
     * @param  Closure(string): mixed  $parse  道を受け取り、解いた値を返す
     * @param  list<class-string>  $allowedClasses  写しを読むときに作ってよいクラス（unserialize の allowed_classes）
     */
    public function remember(string $name, Closure $parse, array $allowedClasses): mixed
    {
        $path = $this->path($name);
        if ($path === null) {
            return null;
        }
        $key = self::keyOf($path);
        if (($this->remembered[$name]['key'] ?? null) === $key) {
            return $this->remembered[$name]['value'];
        }

        $value = $this->readKept($name, $key, $allowedClasses);
        if ($value === null) {
            $value = $parse($path);
            $this->keep($name, $key, $value);
        }
        $this->remembered[$name] = ['key' => $key, 'value' => $value];

        return $value;
    }

    /** foundation.data_files.cache_path（null なら storage_path('framework/foundation/data-files-cache')） */
    public static function cachePath(): string
    {
        $path = config('foundation.data_files.cache_path');

        return is_string($path) && $path !== '' ? rtrim($path, '/\\') : storage_path('framework/foundation/data-files-cache');
    }

    private static function keyOf(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? $path : $path.':'.$stat['ino'].':'.$stat['mtime'].':'.$stat['size'];
    }

    private static function keptFile(string $name, string $key): string
    {
        return self::cachePath().'/'.$name.'-'.hash('sha256', $key).'.ser';
    }

    /** @param  list<class-string>  $allowedClasses */
    private function readKept(string $name, string $key, array $allowedClasses): mixed
    {
        $file = self::keptFile($name, $key);
        try {
            if (! is_file($file)) {
                return null;
            }
            $text = file_get_contents($file);
            if ($text === false) {
                return null;
            }
            // 'b:0;'（false を置いた）と読めない文を見分ける
            $value = @unserialize($text, ['allowed_classes' => array_values($allowedClasses)]);
            if (($value === false && $text !== serialize(false)) || $value === null || self::containsIncomplete($value)) {
                return null;
            }

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function keep(string $name, string $key, mixed $value): void
    {
        $file = self::keptFile($name, $key);
        $directory = dirname($file);
        $temporary = null;
        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }
            // 丸ごとか、何もないか：読んでいる要求に半分のファイルを見せない
            $temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (@file_put_contents($temporary, serialize($value)) === false || ! @rename($temporary, $file)) {
                return;
            }
            // 消すのは、この名前の古い鍵の写しだけ。名前の頭が重なるほかの種類（ip と ip-city）の写しには当てない
            $quoted = preg_quote($name, '/');
            foreach (glob($directory.'/'.$name.'-*.ser', GLOB_NOSORT) ?: [] as $older) {
                if ($older !== $file && preg_match('/\A'.$quoted.'-[0-9a-f]{64}\.ser\z/', basename($older)) === 1) {
                    @unlink($older);
                }
            }
            // 書いてから rename の前に殺されたプロセスが残したもの。1時間より古いもの（書いている途中のものを消さない）
            foreach (glob($directory.'/'.$name.'-*.tmp', GLOB_NOSORT) ?: [] as $leftover) {
                if (preg_match('/\A'.$quoted.'-[0-9a-f]{64}\.ser\.[0-9a-f]{12}\.tmp\z/', basename($leftover)) !== 1) {
                    continue;
                }
                $time = @filemtime($leftover);
                if ($time !== false && $time < time() - Store::LEFTOVER_SECONDS) {
                    @unlink($leftover);
                }
            }
        } catch (Throwable) {
            // 置けなくても、次の要求が解き直すだけ
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function containsIncomplete(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if ($depth > 64 || (! is_array($value) && ! is_object($value))) {
            return false;
        }
        foreach ((array) $value as $item) {
            if (self::containsIncomplete($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function reportMissing(string $name, string $message): void
    {
        try {
            if (app()->environment(['local', 'testing'])) {
                return;
            }
        } catch (Throwable) {
            // 環境が分からなくても知らせる
        }
        $this->reporter->report(new RuntimeException($message), "foundation-data-files-missing:{$name}", self::MISSING_REPORT_SECONDS);
    }
}
