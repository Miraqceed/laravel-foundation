<?php

namespace Miraqceed\Foundation\Mail;

use LogicException;
use Normalizer;

/**
 * 使い捨てのメールのドメインの判定。一覧のファイルはアプリが置く（foundation.mail.disposable_domains）。
 * パッケージは一覧を持たず、取りに行かない。
 *
 * - 一覧は1行に1つのドメイン。# で始まる行と空の行は飛ばす。小文字・前後の空白・前後の点をそろえる
 * - 1つのプロセスで1回だけ読む（容器の singleton。ファイルの道が変われば読み直す）
 * - 判定の前に、アドレスを EmailInput::normalize() でそろえる（全角・＠・句点・末尾の点・大文字）。日本語のドメインは xn-- の形で比べる
 * - 下位のドメインも当たる（一覧に mailinator.com があれば a.mailinator.com も）。点のない名前（トップレベル）だけでは当てない
 * - 設定がない・ファイルが読めないときは LogicException（黙ってすべてを通さない）
 */
final class DisposableEmailDomains
{
    /** @var array<string, true>|null */
    private ?array $domains = null;

    private ?string $loadedFrom = null;

    /** @throws LogicException 一覧の設定がない・ファイルが読めないとき */
    public function contains(string $email): bool
    {
        $domain = self::domainOf($email);
        if ($domain === null) {
            return false;
        }

        $domains = $this->domains();
        $labels = explode('.', $domain);
        // 自分と親のドメイン（2つ以上のラベルのものだけ）
        for ($i = 0; $i < count($labels) - 1; $i++) {
            if (isset($domains[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** そろえたドメイン（ASCII・小文字）。@ がない・ドメインが空なら null */
    private static function domainOf(string $email): ?string
    {
        $normalized = EmailInput::normalize($email);
        $at = strrpos($normalized, '@');
        if ($at === false) {
            return null;
        }

        $domain = substr($normalized, $at + 1);
        if ($domain === '') {
            return null;
        }

        return self::toAscii($domain);
    }

    private static function toAscii(string $domain): string
    {
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            return strtolower($ascii);
        }

        $normalized = Normalizer::normalize($domain, Normalizer::FORM_KC);

        return mb_strtolower(is_string($normalized) ? $normalized : $domain);
    }

    /** @return array<string, true> */
    private function domains(): array
    {
        $path = config('foundation.mail.disposable_domains');
        if (! is_string($path) || $path === '') {
            throw new LogicException('使い捨てのメールの一覧のファイルを foundation.mail.disposable_domains に書いてください（パッケージは一覧を持ちません）');
        }

        if ($this->domains !== null && $this->loadedFrom === $path) {
            return $this->domains;
        }

        $lines = is_file($path) && is_readable($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            throw new LogicException('foundation.mail.disposable_domains のファイルを読めません');
        }

        $domains = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $domain = self::toAscii(trim($line, '.'));
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $this->loadedFrom = $path;

        return $this->domains = $domains;
    }
}
