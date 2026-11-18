<?php

namespace Miraqceed\Foundation\Turnstile;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Turnstile の確かめを通ったか。フォームの欄（既定 cf-turnstile-response）に付ける：
 *
 *   'cf-turnstile-response' => ['required', new TurnstilePassed],
 *   'cf-turnstile-response' => ['required', new TurnstilePassed(action: 'contact')],  // ウィジェットの action も比べる
 *
 * 確かめられなければ断る（閉じる側）。文は1つ（foundation::validation.turnstile）
 */
final class TurnstilePassed implements ValidationRule
{
    public function __construct(private readonly ?string $action = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(Turnstile::class)->passes($value, $this->action)) {
            $fail('foundation::validation.turnstile')->translate();
        }
    }
}
