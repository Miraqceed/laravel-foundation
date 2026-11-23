<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 1回の調べで分け合うもの：根・設定・期限・網・結果
 *
 * @internal
 */
final class Context
{
    /** PHP として動く名前（途中の拡張子も。x.php.jpg は Apache の AddHandler で動く） */
    public const PHP_NAME = '/\.(php\d*|phtml|pht|phps|phar)(\.|$)/i';

    /** 一覧の頭の行の設定（一覧が使えないとき null） */
    public ?Settings $snapshotSettings = null;

    /** 一覧が使えたか（調べ1の後に決まる） */
    public bool $snapshotUsable = false;

    public function __construct(
        public readonly string $root,
        public readonly Settings $current,
        public readonly Deadline $deadline,
        public readonly MemoryGuard $guard,
        public readonly Report $report,
    ) {}

    public static function root(): string
    {
        $root = config('foundation.integrity.root');
        $root = is_string($root) && $root !== '' ? $root : base_path();
        $real = realpath($root);

        return rtrim(str_replace('\\', '/', $real === false ? $root : $real), '/');
    }

    /** 調べ2〜6が使う設定：一覧が使えないときは今の値、使えるときは厳しい方 */
    public function effective(): Settings
    {
        return $this->snapshotSettings === null ? $this->current : $this->current->strictest($this->snapshotSettings);
    }

    /** 根からの道か、根の外なら表示の名前 */
    public function shown(string $absolute, string $outsideLabel): string
    {
        $relative = Settings::relative($this->root, $absolute);

        return str_starts_with($relative, 'outside:') ? $outsideLabel : $relative;
    }

    public static function isPhpName(string $name): bool
    {
        return preg_match(self::PHP_NAME, $name) === 1;
    }
}
