# Turnstile（ボットの確かめ）

名前空間 `Miraqceed\Foundation\Turnstile`。Cloudflare Turnstile のウィジェットのトークンを、サーバーの側で確かめる。**使ったときだけ働く**（規則・ミドルウェアをどこにも付けなければ何も変わらない）。外への要求は Laravel の `Http`。

- `Turnstile::passes($token, ?$action)`：確かめ（`bool`）
- `TurnstilePassed`：フォームの検証の規則
- `VerifyTurnstile`（別名 `foundation.turnstile`）：付けた経路の書き込みの要求で確かめるミドルウェア
- `VerifyTurnstileOnNamedRoutes`：名前で当てた経路だけで確かめるミドルウェア（Fortify の経路のため。web のグループに入れる）
- `foundation:deploy-check` の `turnstile`：本番の鍵がテスト用でないか

## いつ使うか

登録・お問い合わせ・ログイン・再設定の申請など、だれでも送れる口を、機械の送信から守るとき。自分で書くと次を踏む。

- 秘密の鍵がない・Cloudflare に届かない・答えが誤りのときに、通してしまう（開く側に倒す）
- 秘密の鍵を間違えると、すべての送信が断られる。断るだけで何も残さないと、運営が気付かない
- 本番にテスト用の鍵を入れる（`1x…` は誰でも通し、`2x…`・`3x…` は誰も通さない）
- 訪問者の IP を送るとき、逆プロキシの後ろで前段（トンネル・ロードバランサー）の IP を送る
- 手元で外すための切り替えを作り、本番で外れたまま出す

## 1. 鍵を置く

鍵はパッケージの設定に置かない。Laravel の決まりの所に書く：

```php
// config/services.php
'turnstile' => [
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
],
```

- **手元・ステージングは Cloudflare のテスト用の鍵**を使う。サイトの鍵 `1x00000000000000000000AA` と秘密の鍵 `1x0000000000000000000000000000000AA` で、ウィジェットが出て、いつも通る。外す切り替えはない（いつも同じ道を通る）
- 本番にテスト用の鍵が残っていれば、`foundation:deploy-check` の `turnstile` が `FAIL` にする
- ウィジェットを読む元（`https://challenges.cloudflare.com`）を CSP に足す（`docs/security.md` の「外の元を足す」）

## 2. 自前のフォームで確かめる（規則）

```php
use Miraqceed\Foundation\Turnstile\TurnstilePassed;

public function rules(): array
{
    return [
        'email' => ['required', 'email'],
        'cf-turnstile-response' => ['required', new TurnstilePassed],
        // ウィジェットに data-action="contact" を付けたなら、答えの action も比べる
        // 'cf-turnstile-response' => ['required', new TurnstilePassed(action: 'contact')],
    ];
}
```

断ると「ボットでないことの確かめが済んでいません。もう一度お試しください。」（`foundation::validation.turnstile`。`en` は英語）。トークンは1回しか使えないので、断った後はウィジェットを出し直す。

## 3. 規則を足せない口（ミドルウェア）

経路に付ける（付けた経路の POST・PUT・PATCH・DELETE でいつも確かめる。GET は見ない）：

```php
Route::post('/contact', ContactController::class)->middleware('foundation.turnstile');
Route::post('/signup', SignupController::class)->middleware('foundation.turnstile:signup'); // action も比べる
```

**Fortify の経路**（ログイン・登録・再設定の申請）は経路に付けられないので、名前で当てるミドルウェアを web のグループに入れ、経路の名前を設定に書く：

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: \Miraqceed\Foundation\Turnstile\VerifyTurnstileOnNamedRoutes::class);
})

// config/foundation.php
'turnstile' => [
    'routes' => ['login.store', 'register.store', 'password.email'],
],
```

- 名前に当たらない経路では何もしない（`routes` の既定は空）。設定が名前の文字列の配列でなければ `InvalidArgumentException`
- 断ると検証の誤り（422、または前の画面へ戻して欄に誤りを付ける）。欄の名前は `foundation.turnstile.field`（既定 `cf-turnstile-response`）

## 4. 閉じる側に倒す

次のどれでも断る。

| 場合 | 外へ送るか | 記録 |
|---|---|---|
| トークンが文字列でない・空・2048 字を超える | 送らない | しない |
| 秘密の鍵がない | 送らない | **設定の誤り** |
| `invalid-input-secret`・`missing-input-secret`・HTTP 4xx | 送る | **設定の誤り** |
| 届かない（待ちは5秒）・HTTP 5xx・`internal-error` | 送る | Cloudflare に届かない・Cloudflare の側の誤り |
| `invalid-input-response`・`timeout-or-duplicate` など（訪問者の側） | 送る | しない |
| `action`・`hostname` が違う（下の 6） | 送る | しない |

- **設定の誤り**は、`FailureReporter`（`docs/failures.md`）で5分に1回記録する。見張り（`docs/monitoring.md`）が有効なら、知らせも送る（「Turnstile の設定の誤り」。見張りの間引きに従う）。直すまで、守っている送信がすべて断られる
- 届かない・Cloudflare の側の誤りは、記録だけ（一時のことが多い）
- 記録・知らせに、秘密の鍵・トークン・IP を入れない（`error-codes` と HTTP の状態だけ）

## 5. 訪問者の IP を送るか

既定では送らない（`remoteip` は任意で、送らなくても確かめは効く）。送るなら：

```php
'turnstile' => [
    'send_ip' => true,
],
```

- `$request->ip()` を送る。逆プロキシの後ろでは、`trustProxies` で前段の IP・範囲を書かないと、前段（トンネル・ロードバランサー）の IP を送る（`docs/login.md`。`foundation:deploy-check` の `trusted_proxies`）
- 利用者の IP を外へ出すことになる（プライバシーポリシーに書く）

## 6. `hostname`・`action` を比べる

- `action`：規則の `TurnstilePassed(action: 'contact')`、ミドルウェアの `foundation.turnstile:contact` のように渡したときだけ、答えの `action` と比べる（口ごとにウィジェットを分け、別の口のトークンを使い回させない）
- `hostname`：`foundation.turnstile.hostnames`（例：`['app.example.com']`）を書いたときだけ、答えの `hostname` と比べる。サイトの鍵を複数のドメインで使うアプリは、すべてを書く
- **秘密の鍵がテスト用で、環境が `production` でなければ比べない。** テスト用の鍵の答えは、いつも `hostname` が `example.com` で `action` がないため（手元で比べるといつも断る）

## 7. 試し

外への要求は `Http::fake()` で偽る（パッケージの `foundation.test_safety.prevent_stray_requests` とも合う）：

```php
Http::fake([
    'challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'contact']),
]);

$this->post('/contact', ['cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX', ...])->assertRedirect();
```

断る場合は `['success' => false, 'error-codes' => ['invalid-input-response']]`。

## 8. 試みの数えとの順番

Turnstile は「確かめ」の1つ。試みの数え（`docs/attempts.md`）は、Turnstile・入力の検証・権限を通った後に照合を包む。Turnstile の前に数えると、機械の送信で正当な利用者の枠が埋まる。

## してはいけないこと

- 確かめられないとき（秘密がない・届かない）に通さない
- 手元・試しのために、確かめを外す切り替えを作らない（テスト用の鍵と `Http::fake()` を使う）
- 本番にテスト用の鍵を入れない
- `trustProxies` なしで `send_ip` を真にしない
- トークン・秘密の鍵をログ・例外の文に出さない

## 限界

- トークンを1回だけ通すのは Cloudflare の「つもり」で、厳密ではない（使った印は遅れて広がり、同時の・すぐ後の確かめが通ることがある）。同じトークンの2回目を必ず断ることには頼らない。回数は試みの数えで守る
- 中国向けのウィジェット（別の確かめの口）は扱わない
- ボットを完全には止めない。人の手で解いたトークンは通る
