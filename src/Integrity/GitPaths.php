<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * .git の中で一覧に入れるもの：git が設定・hook として読むものだけ（hooks/・config・config.worktree・info/attributes・
 * info/exclude・info/sparse-checkout・modules/<名前>/ の同じもの・worktrees/<名前>/config.worktree）。
 * ほか（objects・refs・index・logs・info/refs）は動かず、git fetch・git gc で変わるので外す
 *
 * @internal
 */
final class GitPaths
{
    private const FILES = ['config', 'config.worktree', 'info/attributes', 'info/exclude', 'info/sparse-checkout'];

    /** 根からの道 $path（.git の下）を外すか。$isFolder はフォルダーか */
    public static function excluded(string $path, bool $isFolder): bool
    {
        if ($path !== '.git' && ! str_starts_with($path, '.git/')) {
            return false;
        }
        if ($path === '.git') {
            return false;
        }
        $inner = substr($path, 5);

        // modules/<名前>/… は、.git の中と同じ決まり
        if (preg_match('#\Amodules/[^/]+(?:/(.*))?\z#', $inner, $match) === 1) {
            if (! isset($match[1])) {
                return ! $isFolder;
            }
            $inner = $match[1];
        } elseif ($inner === 'modules') {
            return ! $isFolder;
        }

        if ($inner === 'worktrees') {
            return ! $isFolder;
        }
        if (preg_match('#\Aworktrees/[^/]+(?:/(.*))?\z#', $inner, $match) === 1) {
            if (! isset($match[1])) {
                return ! $isFolder;
            }

            return $match[1] !== 'config.worktree';
        }

        if ($inner === 'hooks' || str_starts_with($inner, 'hooks/')) {
            return false;
        }
        if ($inner === 'info') {
            return ! $isFolder;
        }

        return ! in_array($inner, self::FILES, true);
    }
}
