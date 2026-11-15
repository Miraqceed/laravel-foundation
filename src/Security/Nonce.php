<?php

namespace Miraqceed\Foundation\Security;

/**
 * 要求ごとの CSP の nonce（base64 の16バイト）。コンテナの scoped で持つ。
 * ミドルウェアが要求の頭で作り直し、Vite のタグと CSP のヘッダーが同じ値を使う。
 */
final class Nonce
{
    public readonly string $value;

    public function __construct()
    {
        $this->value = base64_encode(random_bytes(16));
    }

    public static function get(): string
    {
        return app(self::class)->value;
    }

    /** この要求の nonce を新しく決める（同じプロセスで続けて要求を流しても使い回さない） */
    public static function renew(): string
    {
        app()->forgetInstance(self::class);

        return self::get();
    }
}
