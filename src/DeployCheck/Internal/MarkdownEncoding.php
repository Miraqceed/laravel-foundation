<?php

namespace Miraqceed\Foundation\DeployCheck\Internal;

use Illuminate\Mail\Markdown;

/**
 * Markdown::withSecuredEncoding() が効いているかを読む（protected static で、読み出しの口がない）。
 * Laravel 12.15.0 より前にはないので、呼ぶ前に method_exists(Markdown::class, 'withSecuredEncoding') で確かめる
 *
 * @internal
 */
final class MarkdownEncoding extends Markdown
{
    public static function secured(): bool
    {
        return static::$withSecuredEncoding === true;
    }
}
