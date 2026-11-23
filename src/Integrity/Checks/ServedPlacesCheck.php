<?php

namespace Miraqceed\Foundation\Integrity\Checks;

use Miraqceed\Foundation\Integrity\Context;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\ReadThrough;

/**
 * 調べ4：公開されるが中身が変わる場所（filesystems.links の先・SSL の確認用のフォルダー・served）。一覧に入れず、名前と種類だけを見る：
 * PHP として動く名前・.htaccess・.user.ini・リンク・ハードリンク・ふつうのファイルでないもの。
 * SSL の確認用のフォルダーは、トークンの形の名前のふつうのファイルだけ（下のフォルダーもない）
 *
 * @internal
 */
final class ServedPlacesCheck
{
    /** SSL の確認のトークンの形（下のフォルダーなし） */
    private const TOKEN = '/\A[A-Za-z0-9_-]+\z/';

    public function run(Context $context): void
    {
        $settings = $context->effective();

        $places = [];
        foreach ($settings->acme as $path) {
            $places[$context->root.'/'.$path] = [$path, true];
        }
        foreach ($settings->served as $path) {
            $places[$context->root.'/'.$path] ??= [$path, false];
        }
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $target = (string) $target;
            $shown = $context->shown($target, 'filesystems.links の先');
            $places[$target] ??= [$shown, false];
        }

        foreach ($places as $absolute => [$shown, $acme]) {
            if (! is_dir($absolute) || is_link($absolute)) {
                continue;
            }
            $reader = new ReadThrough($context->deadline, $context->guard, fn (Gap $gap, string $path) => $context->report->notChecked($gap, $path));
            foreach ($reader->walk($absolute, $shown) as $entry) {
                $this->look($entry, $acme, $shown, $context);
            }
            if ($acme) {
                foreach ($this->subfolders($absolute) as $folder) {
                    $context->report->add(Finding::AcmeFile, $shown.'/'.$folder);
                }
            }
            if ($reader->stopped !== null) {
                $context->report->notChecked($reader->stopped, 'served');

                return;
            }
        }
    }

    private function look(Entry $entry, bool $acme, string $root, Context $context): void
    {
        $report = $context->report;
        $name = $entry->name();
        if ($acme) {
            $direct = $entry->path === $root.'/'.$name;
            if (! $direct || $entry->type !== Entry::FILE || preg_match(self::TOKEN, $name) !== 1 || $entry->isHardLinked()) {
                $report->add(Finding::AcmeFile, $entry->path);

                return;
            }
        }
        if ($entry->type === Entry::LINK) {
            $report->add(Finding::ServedLink, $entry->path);
        } elseif ($entry->type === Entry::OTHER) {
            $report->add(Finding::NotRegular, $entry->path);
        } elseif ($entry->type === Entry::UNREADABLE) {
            $report->add(Finding::Unreadable, $entry->path);
        } elseif ($entry->isHardLinked()) {
            $report->add(Finding::ServedHardLink, $entry->path);
        }
        if (Context::isPhpName($name)) {
            $report->add(Finding::ServedPhp, $entry->path);
        } elseif (in_array(strtolower($name), ['.htaccess', '.user.ini'], true)) {
            $report->add(Finding::ServedConfig, $entry->path);
        }
    }

    /** @return list<string> SSL の確認用のフォルダーの直下のフォルダー（あってはならない） */
    private function subfolders(string $absolute): array
    {
        $folders = [];
        $handle = @opendir($absolute);
        if ($handle === false) {
            return [];
        }
        $count = 0;
        while (($name = @readdir($handle)) !== false && $count < 100) {
            if ($name !== '.' && $name !== '..' && ($stat = Entry::lstat($absolute.'/'.$name)) && Entry::typeOf($stat) === 'dir') {
                $folders[] = $name;
                $count++;
            }
        }
        closedir($handle);
        sort($folders, SORT_STRING);

        return $folders;
    }
}
