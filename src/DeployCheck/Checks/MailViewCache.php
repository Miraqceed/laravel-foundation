<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Mail\Markdown;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Internal\MarkdownEncoding;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * view:cache（`php artisan optimize` を --except=views なしで流す）は、メールの画面を素の echo でコンパイルする。
 * 直っていない版の Laravel では、Markdown の描画がそのファイルを使い回し、Markdown::withSecuredEncoding() が
 * そのメールから外れる（訪問者が書いたリンクが、メールの中のリンクになる）。
 *
 * - 直った版（12.70.0・13.36.0 から）は、既定でない echo の形ごとに別のコンパイル済みのファイルを使うので外れない。
 *   版の番号でなく、振る舞い（echo の形を変えるとコンパイル済みの道が変わるか）で見分けて skip にする
 * - 直っていない版では、Markdown が画面をコンパイルすると EncodedHtmlString の echo になるので、既定の道のファイルで見分ける
 *
 * 限界：見るのは通知のメールの画面（notifications::email）だけ
 */
final class MailViewCache implements Check
{
    /** Markdown::render() が使う echo の形 */
    private const MARKDOWN_ECHO_FORMAT = 'new \Illuminate\Support\EncodedHtmlString(%s)';

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

        $compiler = app('blade.compiler');
        $view = app('view')->getFinder()->find('notifications::email');
        $compiled = $compiler->getCompiledPath($view);
        $markdownCompiled = $compiler->usingEchoFormat(self::MARKDOWN_ECHO_FORMAT, fn () => $compiler->getCompiledPath($view));
        if ($markdownCompiled !== $compiled) {
            return Result::skip('この版の Laravel は、メールの画面を echo の形ごとに別のファイルへコンパイルする（view:cache で外れない）');
        }

        if (! is_file($compiled)) {
            return Result::ok();
        }

        return str_contains((string) file_get_contents($compiled), 'EncodedHtmlString')
            ? Result::ok()
            : Result::fail('素の echo でコンパイルされている（php artisan view:clear。optimize は --except=views で流す）');
    }
}
