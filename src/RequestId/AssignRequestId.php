<?php

namespace Miraqceed\Foundation\RequestId;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 要求の id と相関の id を決め、Laravel の Context に入れる（ログの行と、要求の中で入れたキューのジョブに Laravel が渡す）。
 * 応答の見出し X-Request-Id・X-Correlation-Id に付ける。別名 foundation.request-id。アプリが全体の中間の先頭に足したときだけ働く。
 *
 * - 要求の id はいつもここで作る（ULID）。利用者が送った X-Request-Id は読まない（ログを偽らせない）
 * - 上流の X-Correlation-Id は、形が合うときだけ引き継ぐ（改行・制御の字でログの行を偽らせない）。なければ要求の id と同じ
 */
final class AssignRequestId
{
    private const CORRELATION_PATTERN = '/\A[A-Za-z0-9._:\-]{1,128}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();
        $incoming = $request->headers->get('X-Correlation-Id');
        $correlationId = is_string($incoming) && preg_match(self::CORRELATION_PATTERN, $incoming) === 1 ? $incoming : $requestId;

        Context::add(RequestId::REQUEST_ID, $requestId);
        Context::add(RequestId::CORRELATION_ID, $correlationId);

        $response = $next($request);

        // 全体の中間には、例外を応答に変えた後の応答が来る（誤りの応答にも付く）
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
