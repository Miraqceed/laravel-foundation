# ログインのロック

名前空間 `Miraqceed\Foundation\Login`。キャッシュ（`RateLimiter`）で数える。Redis は要らず、`database` のキャッシュで動く。

## いつ使うか

ログイン（メールアドレスとパスワード）の失敗を数えて止めるとき。**数えを自前で作らない。**

- 枠は2つ：①メールアドレスと接続元の組（`account_max` 回 / `decay_seconds` 秒。本人が同じ接続元を共有する他人に巻き込まれない）、②接続元（`client_max` 回。メールを変えながら1回ずつ試すスプレーを止める）。
- 照合の**前**に1回分を足す（照合を待つ間に並んだ要求が揃って通り抜けない）。成功したら①を空にし、②は今回の1回分だけ戻す。
- IPv6 は上位 64 ビット（`/64`）に丸めて数える。IPv4 にマップされた形は IPv4 として数える。
- 設定（`foundation.login`）：`account_max`=5・`decay_seconds`=60・`client_max`=20。`email_only`=true にすると①をメールアドレスだけの枠（`email_only_max`=10・`email_only_decay_seconds`=1800）に置き換える（接続元の枠は残る）。

## 逆プロキシの後ろでは `trustProxies` が必須

ロードバランサー・Caddy・nginx・Cloudflare などの逆プロキシの後ろで動かすときは、**`bootstrap/app.php` で信頼するプロキシを必ず設定する**。設定しないと `$request->ip()` がすべてプロキシの IP になり、全員が同じ接続元に数えられる。接続元の枠（`client_max`）がサイト全体のロックになり、1人がパスワードを間違え続けるだけで誰もログインできなくなる。

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // プロキシの IP・範囲を挙げる。アプリへ直に届かない（プロキシからしか届かない）構成なら '*' でもよい
    $middleware->trustProxies(at: ['10.0.0.0/8']);
})
```

`'*'` は、アプリへ直に届く口があると `X-Forwarded-For` を偽って枠を避けられるので、プロキシからしか届かない構成でだけ使う。CSP の違反の報告の口（`docs/security.md`）の回数の制限も、同じ接続元の数え方なので同じ前提。

## Fortify と組み合わせる（3つとも要る）

```php
// config/fortify.php：経路の throttle を外す
'limiters' => ['login' => null],

// app/Providers/FortifyServiceProvider.php の boot()
use Laravel\Fortify\Fortify;
use Miraqceed\Foundation\Login\Fortify\AuthenticateWithLoginLock;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\NormalizedEmailKey;

// limiters.login が null だと Fortify は自分の EnsureLoginIsNotThrottled を流れに入れる。それを除いた流れに替える
Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline());
Fortify::authenticateUsing(new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey)));
```

`fortify.limiters.login` を `null` にするだけでは足りない（Fortify が別の制限を流れに入れる）。**`authenticateThrough` とセット**。ロック中は 429 の `ValidationException`（Fortify の `auth.throttle` の文言）。

## Fortify なしで使う

```php
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\LoginLockedOut;
use Miraqceed\Foundation\Login\NormalizedEmailKey;

$lock = new LoginLock(new NormalizedEmailKey, scope: 'user'); // 管理画面などは scope を分ける

try {
    $ok = $lock->attempt($request, $email, fn () => Auth::attempt($credentials));
} catch (LoginLockedOut $e) {
    // 照合には進んでいない。$e->retryAfter 秒後に再び試せる（1以上）
    abort(429);
}
```

パスワードの変更・確かめの画面の「現在のパスワード」の失敗は、ログインとは別に数える（ロックしない）：`$lock->attemptPasswordConfirmation($request, $user, fn () => Hash::check(...))`。

## メールアドレスの鍵（`EmailKey`）

- `NormalizedEmailKey`：PHP だけ。NFKC（全角・半角）をそろえ、前後の空白類を落とし、小文字にして sha1。**DB の照合順序と同じ範囲にはならない。**
- `MysqlCollationEmailKey($table, $column)`：MySQL 専用。列の照合順序の重みを DB に計算させ、`where email = ?` が同じ利用者に当たる範囲（大文字小文字・全角・無視される文字・末尾の空白）で枠をそろえる。MySQL でログインの照合を DB の照合順序に任せているなら、こちらを使う。計算できなければ `RuntimeException`（数える前に止まり、照合には進まない）。

## してはいけないこと

- 失敗の数えを自前で作らない（照合の後に足す形だと並列の要求が通り抜ける）。
- 照合が例外を投げても足した分は返らない（意図）。例外で数えを減らそうとしない。
- ミドルウェアの `throttle:login` と併用して二重に数えない。
- 逆プロキシの後ろで `trustProxies` を設定せずに使わない（全員が同じ接続元になる）。

## 限界

- 二段階認証を有効にした Fortify は、認証用の関数を2回続けて呼ぶ。失敗は1回目で終わるので1回しか数えず、成功は呼ぶたびに数えを戻すので何も残らない。
- 数えはキャッシュにある。キャッシュを消せば数えも消える。
