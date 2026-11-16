<?php

namespace Miraqceed\Foundation\Mail;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 実在しないことが決まっているドメイン（ReservedEmailDomains）のメールアドレスを断る。
 *
 * そのアドレスへ後でメールを送る欄（登録・メールアドレスの変更・招待の宛先）に付ける。
 * 確認のメールが届かないうえ、退会した利用者の伏せ字と同じ形のアドレスが埋まると、伏せ字の処理が一意の制約で止まる。
 * ログインの欄には付けない（登録済みのアドレスには「メールアドレスかパスワードが違う」と出すべきため）。
 */
final class NotReservedEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列でない値は通す（型は string の規則の仕事）
        if (is_string($value) && ReservedEmailDomains::isReserved($value)) {
            $fail('foundation::validation.reserved_email')->translate();
        }
    }
}
