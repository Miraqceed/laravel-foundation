<?php

namespace Miraqceed\Foundation\Turnstile;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Monitoring\Alerts\AlertSender;
use Throwable;

/**
 * Cloudflare Turnstile の確かめ（サーバーの側）。閉じる側に倒す：確かめられなければ、いつも断る。
 *
 * - 鍵は services.turnstile.site_key・secret_key（アプリが config/services.php に書く）。パッケージの設定に秘密を置かない
 * - トークンが文字列でない・空・2048 字を超えるなら、外へ送らずに断る
 * - 秘密の鍵がない・違う（missing-input-secret・invalid-input-secret）・HTTP の誤りは、設定の誤りとして5分に1回記録し、
 *   見張りが有効なら知らせる（すべての送信が断られる大きな事故のため）。届かない・Cloudflare の側の誤りは記録だけ。訪問者の側の誤りは記録しない
 * - 訪問者の IP は既定で送らない（foundation.turnstile.send_ip で送る。送るなら trustProxies が要る）
 * - action は渡したときだけ、hostname は foundation.turnstile.hostnames を書いたときだけ比べる。
 *   秘密の鍵がテスト用で production でなければ比べない（テスト用の鍵の答えは hostname が example.com で action がない）。
 *   production でテスト用の秘密の鍵なら、外へ送らずにいつも断り、設定の誤りとして記録して知らせる（テスト用の鍵はいつも success を返す）
 * - 記録・知らせに、秘密の鍵・トークン・IP を入れない
 */
final class Turnstile
{
    /** ウィジェットがフォームに足す欄の名前（既定） */
    public const FIELD = 'cf-turnstile-response';

    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const MAX_TOKEN_LENGTH = 2048;

    /** こちらの鍵の誤り。すべての送信が断られる */
    private const SETUP_ERRORS = ['missing-input-secret', 'invalid-input-secret'];

    /** Cloudflare の側の誤り（一時のことが多い） */
    private const UPSTREAM_ERRORS = ['internal-error'];

    /** Cloudflare のテスト用の鍵（1x・2x・3x の後に0が並ぶ） */
    private const TEST_KEY = '/\A[123]x0{10}/';

    /** 記録の間引きの窓（秒） */
    private const REPORT_WINDOW = 300;

    public function __construct(private readonly FailureReporter $reporter) {}

    /** ウィジェットのトークンが、人の送信として通るか。$action を渡せば、ウィジェットの action も比べる */
    public function passes(mixed $token, ?string $action = null): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $secret = config('services.turnstile.secret_key');
        if (! is_string($secret) || $secret === '') {
            $this->misconfigured('秘密の鍵（services.turnstile.secret_key）がない');

            return false;
        }

        // 本番でテスト用の秘密の鍵なら、比べずにいつも断る。テスト用の 1x の鍵には Cloudflare がいつも success を返すので、
        // action も hostnames も決めていないアプリでは、比べに進むと誰でも通る
        if (preg_match(self::TEST_KEY, $secret) === 1 && app()->environment('production')) {
            $this->misconfigured('秘密の鍵が Cloudflare のテスト用');

            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::ENDPOINT, $this->payload($secret, $token));
        } catch (ConnectionException) {
            $this->unavailable('Cloudflare に届かない');

            return false;
        }

        if (! $response->successful()) {
            $response->serverError()
                ? $this->unavailable('Cloudflare が HTTP '.$response->status().' を返した')
                : $this->misconfigured('Cloudflare が HTTP '.$response->status().' を返した');

            return false;
        }

        if ($response->json('success') !== true) {
            $this->reportErrorCodes($response);

            return false;
        }

        return $this->matchesExpectations($response, $secret, $action);
    }

    /** @return array<string, string> */
    private function payload(string $secret, string $token): array
    {
        $payload = ['secret' => $secret, 'response' => $token];

        if (config('foundation.turnstile.send_ip')) {
            // trustProxies がないと、前段（トンネル・ロードバランサー）の IP になる（docs/turnstile.md）
            $ip = request()->ip();
            if (is_string($ip) && $ip !== '') {
                $payload['remoteip'] = $ip;
            }
        }

        return $payload;
    }

    private function reportErrorCodes(Response $response): void
    {
        $codes = array_values(array_filter((array) $response->json('error-codes', []), 'is_string'));

        if (array_intersect($codes, self::SETUP_ERRORS) !== []) {
            $this->misconfigured('Cloudflare が設定の誤りで断った：'.implode(', ', array_intersect($codes, self::SETUP_ERRORS)));
        } elseif (array_intersect($codes, self::UPSTREAM_ERRORS) !== []) {
            $this->unavailable('Cloudflare が自分の側の誤りを返した：internal-error');
        }
        // 訪問者の側の誤り（invalid-input-response・timeout-or-duplicate など）は記録しない
    }

    private function matchesExpectations(Response $response, string $secret, ?string $action): bool
    {
        // テスト用の秘密の鍵の答えは hostname が example.com で action がない。手元・試しでは比べない
        if (preg_match(self::TEST_KEY, $secret) === 1 && ! app()->environment('production')) {
            return true;
        }

        if ($action !== null && $response->json('action') !== $action) {
            return false;
        }

        $hostnames = config('foundation.turnstile.hostnames');
        if (is_array($hostnames) && $hostnames !== [] && ! in_array($response->json('hostname'), $hostnames, true)) {
            return false;
        }

        return true;
    }

    /** こちらの設定の誤り：記録し、見張りが有効なら知らせる */
    private function misconfigured(string $problem): void
    {
        $this->reporter->report(new TurnstileMisconfigured('Turnstile：'.$problem), 'foundation.turnstile:misconfigured', self::REPORT_WINDOW);

        try {
            app(AlertSender::class)->send(
                'foundation.turnstile:misconfigured',
                'Turnstile の設定の誤り',
                "Turnstile の確かめが、設定の誤りで通りません：{$problem}。\n直すまで、Turnstile で守っている送信（登録・お問い合わせなど）がすべて断られます。",
            );
        } catch (Throwable $e) {
            $this->reporter->report($e, 'foundation.turnstile:alert-failed', self::REPORT_WINDOW);
        }
    }

    /** 届かない・Cloudflare の側の誤り：記録だけ（一時のことが多い） */
    private function unavailable(string $problem): void
    {
        $this->reporter->report(new TurnstileMisconfigured('Turnstile：'.$problem), 'foundation.turnstile:unavailable', self::REPORT_WINDOW);
    }
}
