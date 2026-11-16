<?php

namespace Miraqceed\Foundation\Mail;

use Normalizer;

/**
 * 実在しないことが規格で決まっているメールアドレスの判定（RFC 2606・RFC 6761）。
 *
 * これらへ送っても届かず、送信サーバーには宛先不明の戻り（バウンス）だけが残る。
 * 戻りが続くと送信元の評価が下がり、本物の利用者へのメールまで届きにくくなる。
 * 退会した利用者の伏せ字・E2E の使い捨ての利用者・試しの利用者・設定の書き損じがここに当たる。
 *
 * 判定は設定・DB・DNS を読まない（同じ入力にいつも同じ答え）。
 */
final class ReservedEmailDomains
{
    private const TOP_LEVEL = ['invalid', 'test', 'example', 'localhost'];

    private const DOMAINS = ['example.com', 'example.net', 'example.org'];

    // UTS #46 がラベルの区切りとみなす、全角のピリオド・句点・半角の句点
    private const DOTS = ['．', '。', '｡'];

    /**
     * 1つの素のメールアドレスの、最後の ASCII の `@` より後のドメインが予約かを返す。
     *
     * - 前後の空白・大文字・末尾の点・全角の英数字・全角のピリオドと句点をそろえてから比べる
     * - 点のないドメイン（`a@intranet`）はインターネットの宛先にならないので予約とみなす
     * - 表示名付きのアドレス・URL・複数の宛先を並べた文字列は解かない。ローカル部・`@` の数・ラベルの形は確かめない
     *   （メールアドレスの形の確かめは `email` の規則の仕事）。全角の `＠` は `@` とみなさない
     * - 偽は「予約の名前に当たらない」だけで、形が正しい・実在する・届くことは約束しない
     */
    public static function isReserved(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $domain = rtrim(str_replace(self::DOTS, '.', trim(substr($address, $at + 1))), '.');
        // 点を落とした後の空を「点のないドメイン」として真にしない
        if ($domain === '') {
            return false;
        }

        $labels = explode('.', self::toAscii($domain));
        if (count($labels) === 1) {
            return true;
        }

        if (in_array(end($labels), self::TOP_LEVEL, true)) {
            return true;
        }

        return in_array(implode('.', array_slice($labels, -2)), self::DOMAINS, true);
    }

    /** 全角を半角に、大文字を小文字にした ASCII の名前（日本語のドメインは xn-- の形） */
    private static function toAscii(string $domain): string
    {
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            return strtolower($ascii);
        }

        // 空のラベル・長すぎるラベルなどで IDN にできない値。全角だけは半角にして比べる
        // （小文字にするだけだと、全角の `ｉｎｖａｌｉｄ` が残ってすり抜ける）
        $normalized = Normalizer::normalize($domain, Normalizer::FORM_KC);

        return mb_strtolower(is_string($normalized) ? $normalized : $domain);
    }
}
