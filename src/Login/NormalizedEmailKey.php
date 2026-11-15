<?php

namespace Miraqceed\Foundation\Login;

/**
 * PHP だけで作る鍵。NFKC（全角・半角など）をそろえ、前後の空白類を落とし、小文字にして sha1 にする。
 * DB の照合順序と同じ範囲にはならない。照合順序にそろえたいときは MysqlCollationEmailKey を使う。
 */
final class NormalizedEmailKey implements EmailKey
{
    public function key(string $email): string
    {
        $normalized = \Normalizer::normalize($email, \Normalizer::FORM_KC);
        if ($normalized === false) {
            $normalized = $email;
        }

        // NFKC の後なので、全角の空白やノーブレークスペースは普通の空白になっている
        $trimmed = preg_replace('/\A[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+\z/u', '', $normalized);

        return sha1(mb_strtolower($trimmed ?? $normalized));
    }
}
