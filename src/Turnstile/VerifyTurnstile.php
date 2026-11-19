<?php

namespace Miraqceed\Foundation\Turnstile;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 付けた経路の書き込みの要求（POST・PUT・PATCH・DELETE）で、Turnstile の確かめを求めるミドルウェア（別名 foundation.turnstile）。
 * 規則を足せない口（自前でない経路）に付ける。付けた経路ではいつも確かめる：
 *
 *   Route::post('/contact', ...)->middleware('foundation.turnstile');
 *   Route::post('/contact', ...)->middleware('foundation.turnstile:contact');   // ウィジェットの action も比べる
 *
 * Fortify の経路のように、経路に付けられないものは VerifyTurnstileOnNamedRoutes を web のグループに入れる。
 * 断ると検証の誤り（欄は foundation.turnstile.field）。GET などは見ない
 */
class VerifyTurnstile
{
    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly Turnstile $turnstile) {}

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        if (in_array($request->getMethod(), self::WRITES, true)) {
            $this->verify($request, $action);
        }

        return $next($request);
    }

    /** @throws ValidationException 確かめを通らないとき */
    protected function verify(Request $request, ?string $action): void
    {
        $field = self::field();

        if (! $this->turnstile->passes($request->input($field), $action)) {
            throw ValidationException::withMessages([$field => [trans('foundation::validation.turnstile')]]);
        }
    }

    public static function field(): string
    {
        $field = config('foundation.turnstile.field', Turnstile::FIELD);

        return is_string($field) && $field !== '' ? $field : Turnstile::FIELD;
    }
}
