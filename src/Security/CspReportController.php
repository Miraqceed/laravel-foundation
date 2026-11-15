<?php

namespace Miraqceed\Foundation\Security;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ブラウザが CSP の違反を報告する口。何も保存せず、204 を返し、指示・止めた元・ページ（クエリとフラグメントなし）を
 * ログ csp.violation に書く。誰でも送れるので、大きさ（8KB）と回数（1分に30回）を絞る。
 * web のグループに入れない（セッション・CSRF がない）。
 */
class CspReportController
{
    public const MAX_BYTES = 8192;

    public const PER_MINUTE = 30;

    public function __invoke(Request $request): Response
    {
        $key = 'foundation-csp-report:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response('', 429);
        }
        RateLimiter::hit($key, 60);

        $body = self::readBody($request);
        if ($body === null) {
            return response('', 413);
        }

        $decoded = json_decode($body, true);
        $report = is_array($decoded) ? ($decoded['csp-report'] ?? $decoded[0]['body'] ?? null) : null;

        if (is_array($report)) {
            $field = function (string ...$keys) use ($report): string {
                foreach ($keys as $k) {
                    if (is_string($report[$k] ?? null) && $report[$k] !== '') {
                        return $report[$k];
                    }
                }

                return '';
            };

            Log::warning('csp.violation', [
                'directive' => mb_substr($field('effective-directive', 'effectiveDirective', 'violated-directive'), 0, 64),
                'blocked' => self::withoutQuery($field('blocked-uri', 'blockedURL')),
                'page' => self::withoutQuery($field('document-uri', 'documentURL')),
            ]);
        }

        return response()->noContent();
    }

    /** 上限を1バイト超えるところまでだけ読む（Content-Length がない要求に備える）。超えたら null */
    private static function readBody(Request $request): ?string
    {
        $stream = $request->getContent(true);
        $body = (string) stream_get_contents($stream, self::MAX_BYTES + 1);

        return strlen($body) > self::MAX_BYTES ? null : $body;
    }

    private static function withoutQuery(string $uri): string
    {
        return mb_substr((string) strtok($uri, '?#'), 0, 300);
    }
}
