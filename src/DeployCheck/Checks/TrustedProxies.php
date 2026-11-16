<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Internal\TrustedProxySettings;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * 信頼するプロキシ（bootstrap/app.php の trustProxies(at:, headers:)）。値（アドレス）は出さない。
 *
 * - 既定（foundation.deploy_check.proxies が null）は info だけ。逆プロキシの後ろかどうかは、パッケージには分からない
 * - 'required'（訪問者の本当の IP が要るアプリ）では、範囲を挙げていなければ失敗：未設定・'*'・'**'（版と見出しに関わらず）・読めない
 * - Laravel 13.20.0 から '*' はすべてのアドレスを信頼し、$request->ip() が X-Forwarded-For の左端（訪問者が送れる値）になる
 */
final class TrustedProxies implements Check
{
    /** 訪問者の IP を運ぶ見出し（Forwarded は RFC 7239 の for=） */
    private const IP_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_FORWARDED;

    public function id(): string
    {
        return 'trusted_proxies';
    }

    public function label(): string
    {
        return '信頼するプロキシ（foundation.deploy_check.proxies が required なら、前段の IP・範囲を書いている）';
    }

    public function run(): Result
    {
        $required = config('foundation.deploy_check.proxies') === 'required';
        $judge = fn (bool $ok, string $detail): Result => ! $required ? Result::info($detail) : ($ok ? Result::ok($detail) : Result::fail($detail));

        // withMiddleware() の設定（trustProxies(at:) を含む）は、HTTP の Kernel を作るときに入る。artisan の中ではまだ入っていない
        $kernel = app(HttpKernel::class);
        $global = method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [];
        foreach ($global as $middleware) {
            if (is_string($middleware) && $middleware !== TrustProxies::class && is_subclass_of($middleware, TrustProxies::class)) {
                return $judge(false, '読めない（アプリの TrustProxies の子が全体のミドルウェアにある）');
            }
        }

        $settings = new TrustedProxySettings;
        $proxies = $settings->trusted();
        $carriesIp = ($settings->trustedHeaders() & self::IP_HEADERS) !== 0;

        if ($proxies === null || $proxies === '' || $proxies === []) {
            return $judge(false, '未設定（逆プロキシの後ろでは、全員がプロキシの IP になる）');
        }

        if (is_string($proxies) && in_array(trim($proxies), ['*', '**'], true)) {
            if (! $carriesIp) {
                return $judge(false, "'*'（IP を運ぶ見出しは信頼しない）：全員がプロキシの IP になる");
            }
            if (version_compare(Application::VERSION, '13.20.0', '>=')) {
                return $judge(false, "'*'：訪問者が X-Forwarded-For で IP を選べる（Laravel 13.20 以降）。前段の IP・範囲を書く");
            }

            return $judge(false, "'*'：今は前段の IP を信じるだけだが、Laravel 13.20 以降に上げると訪問者が IP を選べる。前段の IP・範囲を書く");
        }

        if (! is_string($proxies) && ! is_array($proxies)) {
            return $judge(false, '読めない（信頼するプロキシの値が文字でも配列でもない）');
        }

        $count = is_array($proxies) ? count($proxies) : count(array_filter(array_map('trim', explode(',', $proxies))));

        return $judge(true, "前段の IP・範囲: {$count} 件");
    }
}
