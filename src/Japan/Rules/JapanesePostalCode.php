<?php

namespace Miraqceed\Foundation\Japan\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Miraqceed\Foundation\Japan\PostalCode;

/**
 * 7桁でない郵便番号を断る（〒・全角・ハイフン・空白は読む）。保存する値は PostalCode::format() で作る（規則は値を変えない）
 */
final class JapanesePostalCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列でない値は通す（型は string の規則の仕事）
        if (is_string($value) && PostalCode::format($value) === null) {
            $fail('foundation::validation.japanese_postal_code')->translate();
        }
    }
}
