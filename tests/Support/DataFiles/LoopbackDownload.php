<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use Closure;
use Miraqceed\Foundation\DataFiles\Download;

/**
 * 試しの助け：手元の HTTP の口（http://127.0.0.1:番号）への Download を作る。
 * Download の private の口を Closure::bind で呼ぶ。tests/ は配布物に入らない（.gitattributes の export-ignore）ので、
 * アプリが入れたパッケージにはこの助けがない
 */
final class LoopbackDownload
{
    public static function url(string $url): Download
    {
        /** @var Closure(string): Download $open */
        $open = Closure::bind(static fn (string $url): Download => Download::loopbackForTests($url), null, Download::class);

        return $open($url);
    }
}
