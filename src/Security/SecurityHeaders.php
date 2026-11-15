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
            // Report-Only の CSP では frame-ancestors が効かないので、いつも付ける
            'X-Frame-Options' => 'DENY',
        ] + ContentSecurityPolicy::headers($request, $response);

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
}
