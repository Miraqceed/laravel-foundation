<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Internal\TrustedProxySettings;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * Host を偽った要求を入口で断るか（bootstrap/app.php の trustHosts()）。値（Host・アドレス）は出さない。
 *
 * - TrustHosts が全体のミドルウェアにあれば ok
 * - なければ、どのアドレスからでも Host を運ぶ見出しを信じる設定（'*'・'**' で X-Forwarded-Host か Forwarded を信頼）は fail。
 *   訪問者が直に送った X-Forwarded-Host が url() のリンクになり、Web サーバーの設定では閉じない
 * - そうでなければ later：Web サーバーが知らない Host を断っていれば穴はない（パッケージからは見えない）
 */
final class TrustedHosts implements Check
{
    /** Host を運ぶ見出し（Forwarded は RFC 7239 の host=） */
    private const HOST_HEADERS = Request::HEADER_X_FORWARDED_HOST | Request::HEADER_FORWARDED;

    public function id(): string
    {
        return 'trusted_hosts';
    }

    public function label(): string
    {
        return '偽の Host を入口で断る（bootstrap/app.php の trustHosts()）';
    }

    public function run(): Result
    {
        // withMiddleware() の設定（trustHosts()・trustProxies() を含む）は、HTTP の Kernel を作るときに入る。artisan の中ではまだ入っていない
        $kernel = app(HttpKernel::class);
        $global = method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [];

        $unreadableProxies = false;
        foreach ($global as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            // アプリの TrustHosts の子（古い作りの App\Http\Middleware\TrustHosts）も同じ守り
            if (is_a($middleware, TrustHosts::class, true)) {
                return Result::ok();
            }
            if ($middleware !== TrustProxies::class && is_subclass_of($middleware, TrustProxies::class)) {
                $unreadableProxies = true;
            }
        }

        if ($unreadableProxies) {
            return Result::later('TrustHosts がない。信頼する見出しは読めない（アプリの TrustProxies の子が全体のミドルウェアにある）');
        }

        $settings = new TrustedProxySettings;
        $proxies = $settings->trusted();
        $everyone = is_string($proxies) && in_array(trim($proxies), ['*', '**'], true);

        if ($everyone && ($settings->trustedHeaders() & self::HOST_HEADERS) !== 0) {
            return Result::fail("TrustHosts がなく、'*' で Host を運ぶ見出し（X-Forwarded-Host・Forwarded）を信じる：訪問者が直に送った Host がリンクになる。trustHosts() を足すか、trustProxies の headers: から外す");
        }

        return Result::later('TrustHosts がない（Web サーバーが知らない Host を断っていれば穴はない）');
    }
}
