<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Mail\Markdown;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Internal\MarkdownEncoding;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * view:cache（`php artisan optimize` を --except=views なしで流す）は、メールの画面を素の echo でコンパイルする。
 * Markdown::withSecuredEncoding() が、そのメールから外れる（訪問者が書いたリンクが、メールの中のリンクになる）。
 * Markdown が画面をコンパイルすると EncodedHtmlString の echo になるので、コンパイル済みのファイルで見分ける。
 * 限界：見るのは通知のメールの画面（notifications::email）だけ
 */
final class MailViewCache implements Check
{
    public function id(): string
    {
        return 'mail_view_cache';
    }

    public function label(): string
    {
        return 'メールの画面が view:cache でコンパイルされていない（withSecuredEncoding が外れない）';
    }

    public function run(): Result
    {
        if (! method_exists(Markdown::class, 'withSecuredEncoding')) {
            return Result::skip('Laravel 12.15 より前（withSecuredEncoding がない）');
        }
        if (! MarkdownEncoding::secured()) {
            return Result::skip('Markdown::withSecuredEncoding() を使っていない');
        }

        $view = app('view')->getFinder()->find('notifications::email');
        $compiled = app('blade.compiler')->getCompiledPath($view);
        if (! is_file($compiled)) {
            return Result::ok();
        }

        return str_contains((string) file_get_contents($compiled), 'EncodedHtmlString')
            ? Result::ok()
            : Result::fail('素の echo でコンパイルされている（php artisan view:clear。optimize は --except=views で流す）');
    }
}
