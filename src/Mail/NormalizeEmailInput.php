<?php

namespace Miraqceed\Foundation\Mail;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use InvalidArgumentException;

/**
 * メールアドレスの欄を、検証の前に EmailInput::normalize() でそろえるミドルウェア。アプリが入れる（パッケージは入れない）：
 *
 *   // bootstrap/app.php
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->web(append: \Miraqceed\Foundation\Mail\NormalizeEmailInput::class);
 *   })
 *
 * 当てる欄は foundation.mail.input_fields（既定 ['email']）の名前。入れ子（rows.0.email）と、
 * それぞれの <名前>_confirmation（confirmed の規則の相手）も同じに整える。JSON の本文も同じ。
 * 文字列でない値・壊れた UTF-8 は変えない（検証で断らせる）
 */
class NormalizeEmailInput extends TransformsRequest
{
    /** @var list<string>|null 1つの要求の中で、設定を1回だけ読む */
    private ?array $names = null;

    public function handle($request, \Closure $next)
    {
        $this->names = null;

        return parent::handle($request, $next);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value) || ! $this->matches((string) $key)) {
            return $value;
        }

        return EmailInput::normalize($value);
    }

    private function matches(string $key): bool
    {
        // 入れ子の欄は rows.0.email の形で来る。最後の名前で当てる
        $leaf = ($dot = strrpos($key, '.')) === false ? $key : substr($key, $dot + 1);

        foreach ($this->names() as $name) {
            if ($leaf === $name || $leaf === $name.'_confirmation') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = config('foundation.mail.input_fields', ['email']);
        if (! is_array($names) || array_filter($names, fn ($name) => ! is_string($name) || $name === '') !== []) {
            // 書き違いを黙って既定に寄せない
            throw new InvalidArgumentException('foundation.mail.input_fields は、欄の名前の文字列の配列にしてください');
        }

        return $this->names = array_values($names);
    }
}
