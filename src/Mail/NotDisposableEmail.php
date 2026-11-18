<?php

namespace Miraqceed\Foundation\Mail;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 使い捨てのメールのドメイン（DisposableEmailDomains。一覧はアプリが置く）のアドレスを断る。
 *
 * 登録・メールアドレスの変更の欄に付ける。ログインの欄には付けない（登録済みのアドレスには「メールアドレスかパスワードが違う」と出すべきため）。
 * 一覧の設定がない・読めないときは LogicException（黙って通さない）
 */
final class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列でない値は通す（型は string の規則の仕事）
        if (is_string($value) && app(DisposableEmailDomains::class)->contains($value)) {
            $fail('foundation::validation.disposable_email')->translate();
        }
    }
}
