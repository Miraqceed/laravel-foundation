<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 歩きで見つけた項目1つ（フォルダーでないもの）。道は根からの道
 *
 * @internal
 */
final class Entry
{
    public const FILE = 'file';

    public const LINK = 'link';

    /** 名前つきのパイプ・ソケット・デバイスなど */
    public const OTHER = 'other';

    /** lstat できない（権限など） */
    public const UNREADABLE = 'unreadable';

    /** @var (\Closure(string): void)|null 試しの継ぎ目。本番のコードからは使わない */
    public static ?\Closure $beforeLstatForTests = null;

    /**
     * @param  array<int|string, int>|null  $stat  lstat の結果
     * @param  bool  $readThrough  項目が多すぎるフォルダーの中を読み流したもの（比べない。名前と種類だけ見る）
     */
    public function __construct(
        public readonly string $path,
        public readonly string $absolute,
        public readonly string $type,
        public readonly ?array $stat,
        public readonly bool $readThrough = false,
    ) {}

    public function name(): string
    {
        $slash = strrpos($this->path, '/');

        return $slash === false ? $this->path : substr($this->path, $slash + 1);
    }

    /** ふつうのファイルで、ほかの名前からも指されている（ハードリンク） */
    public function isHardLinked(): bool
    {
        return $this->type === self::FILE && ($this->stat['nlink'] ?? 1) > 1;
    }

    /** lstat の種類を見る（リンクを辿らない） */
    public static function typeOf(?array $stat): string
    {
        if ($stat === null) {
            return self::UNREADABLE;
        }
        $mode = $stat['mode'] & 0170000;

        return match ($mode) {
            0100000 => self::FILE,
            0120000 => self::LINK,
            0040000 => 'dir',
            default => self::OTHER,
        };
    }

    /**
     * lstat を、警告を出さずに（警告を ErrorException にされずに）取る。消えていれば false、読めなければ null
     *
     * @return array<int|string, int>|false|null
     */
    public static function lstat(string $absolute): array|false|null
    {
        if (self::$beforeLstatForTests !== null) {
            // 試しの継ぎ目：1つの道だけ投げる（警告から来た ErrorException の形）。項目ごとの受け止めを確かめる
            (self::$beforeLstatForTests)($absolute);
        }
        try {
            $stat = @lstat($absolute);
        } catch (\Throwable) {
            $stat = false;
        }
        if ($stat !== false) {
            return $stat;
        }
        clearstatcache(true, $absolute);

        // 読めないのか、消えたのか（readdir の後、lstat の前に消えた項目は、なかったものとして扱う）。
        // 親のフォルダーを辿れない（x がない）ときは、file_exists も偽になるので、消えたとはみなさない
        $parent = dirname($absolute);
        $searchable = ! @is_dir($parent) || @is_executable($parent);

        return $searchable && ! @file_exists($absolute) && ! @is_link($absolute) ? false : null;
    }
}
