<?php

namespace Miraqceed\Foundation\Integrity;

use Throwable;

/**
 * 一覧を1行ずつ読む。行の長さの上限までしか読まない（大きさの上限は持たない。大きな一覧は期限で終わる）。
 * 項目の並びが厳しく増えるか・終わりの行の件数と指紋が合うかを確かめ、外れれば broken（そこまでの違いは呼び手が出す）
 *
 * @internal
 */
final class SnapshotReader
{
    public ?Settings $settings = null;

    /** 並び・形・終わりの行が合わなかった */
    public bool $broken = false;

    /** 終わりの行まで読んで、合った */
    public bool $finished = false;

    private \HashContext $hash;

    private int $count = 0;

    private ?string $last = null;

    /** @param  resource  $handle */
    private function __construct(private $handle, private readonly int $size)
    {
        $this->hash = hash_init('sha256');
    }

    public static function open(string $path, string $rootFingerprint): self
    {
        clearstatcache(true, $path);
        // lstat と fopen の間に、一覧の命令が rename で入れ替えることがある（ふつうのこと）。何回か見直す
        for ($attempt = 0; ; $attempt++) {
            clearstatcache(true, $path);
            $stat = Entry::lstat($path);
            if ($stat === false) {
                throw new SnapshotUnavailable(Gap::NoSnapshot);
            }
            if ($stat === null || Entry::typeOf($stat) !== Entry::FILE) {
                throw new SnapshotUnavailable(Gap::BadSnapshot);
            }
            try {
                $handle = @fopen($path, 'rb');
            } catch (Throwable) {
                $handle = false;
            }
            if ($handle === false) {
                throw new SnapshotUnavailable(Gap::BadSnapshot);
            }
            $opened = @fstat($handle);
            if ($opened !== false && ($opened['mode'] & 0170000) === 0100000 && $opened['ino'] === $stat['ino']) {
                break;
            }
            fclose($handle);
            if ($attempt >= 4) {
                throw new SnapshotUnavailable(Gap::BadSnapshot);
            }
        }

        $reader = new self($handle, (int) $opened['size']);
        $head = $reader->line(Limits::HEAD_BYTES);
        $prefix = Snapshot::MAGIC.' '.Snapshot::VERSION.' ';
        if ($head === null || ! str_starts_with($head, $prefix)) {
            $reader->close();
            throw new SnapshotUnavailable(Gap::BadSnapshot);
        }
        try {
            $data = json_decode(substr($head, strlen($prefix)), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $reader->close();
            throw new SnapshotUnavailable(Gap::BadSnapshot);
        }
        $settings = is_array($data) && is_array($data['settings'] ?? null) ? Settings::fromArray($data['settings']) : null;
        if (! is_array($data) || ! is_string($data['root'] ?? null) || $settings === null) {
            $reader->close();
            throw new SnapshotUnavailable(Gap::BadSnapshot);
        }
        if (! hash_equals($data['root'], $rootFingerprint)) {
            $reader->close();
            throw new SnapshotUnavailable(Gap::OtherRoot);
        }
        $reader->settings = $settings;

        return $reader;
    }

    /**
     * 次の項目。終わり（か壊れ）で null
     *
     * @return array{0: string, 1: string, 2: string}|null 道・種類（F・L）・指紋（16進）
     */
    public function next(): ?array
    {
        if ($this->broken || $this->finished) {
            return null;
        }
        $line = $this->line(Limits::LINE_BYTES);
        if ($line === null) {
            // 終わりの行がない（途中で切られた一覧）・長すぎる行
            $this->broken = true;
            $this->close();

            return null;
        }
        if (preg_match('/\Aend (\d{1,10}) ([0-9a-f]{64})\z/', $line, $match) === 1) {
            $this->finished = (int) $match[1] === $this->count && hash_equals(hash_final($this->hash), $match[2])
                && $this->line(16) === null && feof($this->handle);
            $this->broken = ! $this->finished;
            $this->close();

            return null;
        }
        if (preg_match('/\A([FL]) ((?:[0-9a-f]{2})+) ([0-9a-f]*)\z/', $line, $match) !== 1
            || ($match[1] === 'F' && strlen($match[3]) !== 64)) {
            $this->broken = true;
            $this->close();

            return null;
        }
        $path = (string) hex2bin($match[2]);
        if ($this->last !== null && PathOrder::compare($this->last, $path) >= 0) {
            // 並びの崩れ（入れ替えた・同じ道が2回）：比べの指し位置がずれたまま進まないよう、ここで止める
            $this->broken = true;
            $this->close();

            return null;
        }
        $this->last = $path;
        $this->count++;
        hash_update($this->hash, $line."\n");

        return [$path, $match[1], $match[3]];
    }

    /** 点検：最後の行が終わりの行の形か（中身の指紋は調べが確かめる） */
    public function tailLooksComplete(): bool
    {
        try {
            $from = max(0, $this->size - Limits::LINE_BYTES - 128);
            fseek($this->handle, $from);
            $tail = (string) fread($this->handle, Limits::LINE_BYTES + 128);
            $this->close();

            return preg_match('/(?:\A|\n)end \d{1,10} [0-9a-f]{64}\n\z/', $tail) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
    }

    /** 改行までの1行（改行を除く）。$max を超える・改行のないまま終わる・読めなければ null */
    private function line(int $max): ?string
    {
        if (! is_resource($this->handle)) {
            return null;
        }
        try {
            $line = @fgets($this->handle, $max + 2);
        } catch (Throwable) {
            return null;
        }
        if ($line === false || ! str_ends_with($line, "\n")) {
            return null;
        }

        return substr($line, 0, -1);
    }
}
