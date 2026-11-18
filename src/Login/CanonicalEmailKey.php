<?php

namespace Miraqceed\Foundation\Login;

use Miraqceed\Foundation\Mail\EmailInput;

/**
 * 比べの形（EmailInput::canonical()）で作る鍵。大文字・全角・Gmail の点と + 以降の違いを同じアドレスとみなす。
 * 一意の列に canonical() を入れるアプリは、回数の制限・押さえ（foundation.email_changes.email_key）もこの鍵にして、範囲をそろえる
 */
final class CanonicalEmailKey implements EmailKey
{
    public function key(string $email): string
    {
        return sha1(EmailInput::canonical($email));
    }
}
