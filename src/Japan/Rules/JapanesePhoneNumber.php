<?php

namespace Miraqceed\Foundation\Japan\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Miraqceed\Foundation\Japan\PhoneNumber;

/**
 * 区切れない日本の電話番号を断る。文は理由で分ける：番号の形でない（shape）と、形は合うが区切りが決まらない（grouping）。
 * 区切っても通らない番号に「ハイフンで区切って」と言わない。保存する値は PhoneNumber::format() で作る（規則は値を変えない）
 */
final class JapanesePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列でない値は通す（型は string の規則の仕事）
        if (! is_string($value)) {
            return;
        }

        match (PhoneNumber::problem($value)) {
            'shape' => $fail('foundation::validation.japanese_phone_number')->translate(),
            'grouping' => $fail('foundation::validation.japanese_phone_number_grouping')->translate(),
            default => null,
        };
    }
}
