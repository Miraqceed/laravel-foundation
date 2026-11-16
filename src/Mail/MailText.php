<?php

namespace Miraqceed\Foundation\Mail;

use Illuminate\Support\HtmlString;

/**
 * 訪問者が書いた文（名前・会社名など）を、Markdown のメールの中で文字のまま出す形にする。
 *
 * そのまま渡すと、名前に書いた `[ここを押す](https://…)` が、このサービスから出たメールの中のリンクになる。
 * Laravel の Markdown::withSecuredEncoding() は 12.15 からで、12.70・13.36 より前は view:cache の後に外れる。
 * これは版にも view:cache にもよらない。
 *
 * 文字列につなぐと HtmlString でなくなるので、つないだ全体を渡す：MailText::escape($user->name.' さん')
 */
final class MailText
{
    /**
     * HTML と Markdown の特別な字を、数値の文字参照（`&#45;` など）にする。改行は空白にする。
     *
     * `\` で打ち消さない：text の版（Markdown::renderText()）は Markdown を HTML に変えず、
     * 実体参照だけを戻すので、`\` が見えたまま残る。数値の文字参照は、HTML の版では CommonMark が
     * 文字として扱い（記法にならない）、text の版では元の字に戻る
     */
    public static function escape(string $text): HtmlString
    {
        // 改行で段落・見出し・引用・参照の定義を作らせない
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

        // u を付けない：ASCII の字だけを見るので、UTF-8 の多バイトの字はそのまま通る（壊れた UTF-8 でも止まらない）
        $text = (string) preg_replace_callback(
            '/[&<>"\'\\\\`*_{}\[\]()#+\-.!|~:]/',
            fn (array $match) => self::reference($match[0]),
            $text,
        );

        // 頭の空白・タブは字下げ（コードの塊）になるので、文字参照にする
        $text = (string) preg_replace_callback(
            '/^[ \t]+/',
            fn (array $match) => implode('', array_map(self::reference(...), str_split($match[0]))),
            $text,
        );

        return new HtmlString($text);
    }

    private static function reference(string $char): string
    {
        return '&#'.ord($char).';';
    }
}
