<?php

namespace Miraqceed\Foundation\Integrity;

use RuntimeException;

/**
 * 一覧を、歩きながら1行ずつ一時のファイルに書き、終わりの行・fsync・rename で入れ替える（調べがいつ読んでもよいように）
 *
 * @internal
 */
final class SnapshotWriter
{
    /** @var resource */
    private $handle;

    private readonly string $temporary;

    private \HashContext $hash;

    private int $count = 0;

    private ?string $last = null;

    /** @param  array<string, mixed>  $head */
    public function __construct(private readonly string $path, array $head)
    {
        Snapshot::ensureDirectory($path);
        $this->temporary = dirname($path).'/'.Snapshot::TEMPORARY_PREFIX.bin2hex(random_bytes(8));
        $handle = @fopen($this->temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('一覧の一時のファイルを作れません');
        }
        $this->handle = $handle;
        $this->hash = hash_init('sha256');
        $this->put(Snapshot::MAGIC.' '.Snapshot::VERSION.' '.json_encode($head, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    public function file(string $path, string $sha256): void
    {
        $this->item('F '.bin2hex($path).' '.$sha256, $path);
    }

    public function link(string $path, string $targetHex): void
    {
        $this->item('L '.bin2hex($path).' '.$targetHex, $path);
    }

    public function count(): int
    {
        return $this->count;
    }

    /** 終わりの行を書き、fsync して置き場所に入れ替える */
    public function commit(): void
    {
        $this->put('end '.$this->count.' '.hash_final($this->hash)."\n");
        if (function_exists('fsync')) {
            @fsync($this->handle);
        }
        fclose($this->handle);
        $mode = is_file($this->path) ? (fileperms($this->path) & 0777) : 0640;
        @chmod($this->temporary, $mode);
        if (! @rename($this->temporary, $this->path)) {
            @unlink($this->temporary);
            throw new RuntimeException('一覧を入れ替えられません');
        }
    }

    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        @unlink($this->temporary);
    }

    private function item(string $line, string $path): void
    {
        if ($this->last !== null && PathOrder::compare($this->last, $path) >= 0) {
            throw new RuntimeException('一覧の項目の並びが崩れています（歩きの順の誤り）');
        }
        if (strlen($line) > Limits::LINE_BYTES) {
            throw new RuntimeException('一覧の項目の行が長すぎます');
        }
        $this->last = $path;
        $this->count++;
        hash_update($this->hash, $line."\n");
        $this->put($line."\n");
    }

    private function put(string $data): void
    {
        if (@fwrite($this->handle, $data) !== strlen($data)) {
            throw new RuntimeException('一覧を書けません');
        }
    }
}
