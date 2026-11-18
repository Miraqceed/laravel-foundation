<?php

namespace Miraqceed\Foundation\Accounts\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Miraqceed\Foundation\Accounts\EmailChanges;

/**
 * 押さえられたメールアドレス（ほかのアカウントが変更を戻せる間の、古いアドレス）を断る。登録と変更の欄に付ける。
 *
 *   'email' => ['required', 'email', new NotHeldEmail],                    // 登録
 *   'email' => ['required', 'email', new NotHeldEmail($request->user())],  // 変更（本人の押さえは数えない）
 *
 * 比べは EmailKey（foundation.email_changes.email_key）。文は1つで、押さえの理由を出さない。
 * foundation.email_changes.enabled が真で、表があるときに使う
 */
final class NotHeldEmail implements ValidationRule
{
    public function __construct(private readonly ?Model $for = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列でない値は通す（型は string の規則の仕事）
        if (is_string($value) && app(EmailChanges::class)->isHeld($value, $this->for)) {
            $fail('foundation::validation.email_held')->translate();
        }
    }
}
