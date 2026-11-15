<?php

namespace Miraqceed\Foundation\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * 応答に防御のヘッダーを付ける。別名 foundation.security-headers はいつも登録されるが、
 * foundation.security.enabled が偽なら何もしない。グループへは自動で足さない（アプリが足す）。
 * 応答がすでに決めている見出しは上書きしない。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('foundation.security.enabled')) {
            return $next($request);
        }

        // 画面を描く前に nonce を決める。Vite のタグと CSP のヘッダーが同じ値を使う
        Vite::useCspNonce(Nonce::renew());

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => (string) config('foundation.security.permissions_policy'),
        ];
        // Report-Only の CSP では frame-ancestors が効かないので、同じ決まりを X-Frame-Options でも出す
        $frame = self::frameOptions(ContentSecurityPolicy::area($request));
        if ($frame !== null) {
            $headers['X-Frame-Options'] = $frame;
        }
        $headers += ContentSecurityPolicy::headers($request, $response);

        if (config('foundation.security.hsts') && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * 区分の frame-ancestors から X-Frame-Options を決める（強制・Report-Only のどちらでも同じ）。
     * 'none'（空の並びも同じ）か、frame-ancestors がなければ DENY、'self' だけなら SAMEORIGIN、
     * ほかの元を含めば X-Frame-Options では表せないので付けない（null）。
     */
    public static function frameOptions(string $area): ?string
    {
        $areas = (array) config('foundation.security.areas', []);
        $directives = (array) ($areas[$area] ?? $areas['web'] ?? []);
        if (! array_key_exists('frame-ancestors', $directives)) {
            return 'DENY';
        }

        $sources = array_values(array_unique(array_filter(array_map(
            fn ($s) => strtolower(trim((string) $s)),
            (array) $directives['frame-ancestors'],
        ), fn (string $s) => $s !== '')));

        return match ($sources) {
            [], ["'none'"] => 'DENY',
            ["'self'"] => 'SAMEORIGIN',
            default => null,
        };
    }
}
