# ログインのロック

名前空間 `Miraqceed\Foundation\Login`。キャッシュ（`RateLimiter`）で数える。Redis は要らず、`database` のキャッシュで動く。

## いつ使うか

ログイン（メールアドレスとパスワード）の失敗を数えて止めるとき。**数えを自前で作らない。**

- 枠は2つ：①メールアドレスと接続元の組（`account_max` 回 / `decay_seconds` 秒。本人が同じ接続元を共有する他人に巻き込まれない）、②接続元（`client_max` 回。メールを変えながら1回ずつ試すスプレーを止める）。
- 照合の**前**に1回分を足す（照合を待つ間に並んだ要求が揃って通り抜けない）。成功したら①を空にし、②は今回の1回分だけ戻す。
- IPv6 は上位 64 ビット（`/64`）に丸めて数える。IPv4 にマップされた形は IPv4 として数える。
- 数えは試みの数え（`docs/attempts.md`）の上で動く。鍵は `APP_KEY` の HMAC（キャッシュの表の鍵から IP・メールアドレスを戻せない）。`APP_KEY` が空なら止まる。
- 設定（`foundation.login`）：`account_max`=5・`decay_seconds`=60・`client_max`=20。`email_only`=true にすると①をメールアドレスだけの枠（`email_only_max`=10・`email_only_decay_seconds`=1800）に置き換える（接続元の枠は残る）。

## 逆プロキシの後ろでは `trustProxies` が必須

ロードバランサー・Caddy・nginx・Cloudflare などの逆プロキシの後ろで動かすときは、**`bootstrap/app.php` で信頼するプロキシを必ず設定する**。設定しないと `$request->ip()` がすべてプロキシの IP になり、全員が同じ接続元に数えられる。接続元の枠（`client_max`）がサイト全体のロックになり、1人がパスワードを間違え続けるだけで誰もログインできなくなる。

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // 前段のプロキシの IP・範囲を挙げる（'*' にしない。下の表）。
    // CDN（Cloudflare など）を前に置くなら、その CDN が公開しているアドレスの範囲も挙げる
    $middleware->trustProxies(at: ['10.0.0.0/8']);
})
```

**`'*'`（`'**'`）にしない。** Laravel 13.20.0 から、`'*'` の意味が変わった：

| Laravel | `'*'`・`'**'` で信頼するもの | `$request->ip()` |
|---|---|---|
| 12.0〜13.19 | 呼んできた相手（`REMOTE_ADDR`）だけ | `X-Forwarded-For` の右端（前段のプロキシが足した値） |
| 13.20〜 | すべてのアドレス | `X-Forwarded-For` の左端。前段のプロキシが、訪問者の送った `X-Forwarded-For` を消さずに足す作り（nginx の `$proxy_add_x_forwarded_for`・Cloudflare など）なら、**訪問者が送った値** |

- 13.20 以降で `'*'` にすると、要求ごとに偽の IP を送るだけで、接続元の枠（`client_max`）と、メールアドレスと接続元の組の枠を避けられる（`email_only` の枠だけが残る）。
- 13.19 までのアプリも、`composer update` で 13.20 以降に上がった時点で同じになる。版に関わらず、範囲を書く。アプリへ直に届く口があると、どの版でも `'*'` は偽られる。
- 範囲を挙げると、`X-Forwarded-For` を右から見て、挙げた範囲のアドレスを外し、最初に残ったアドレスを訪問者の IP にする（前段が何段あっても、すべて挙げた範囲の中なら同じ）。

**前段のプロキシのアドレスが分からないとき**は、`X-Forwarded-For` を信頼しない書き方にする。`'*'` でも IP は偽られない：

```php
use Illuminate\Http\Request;

// https かどうか（X-Forwarded-Proto）だけを前段から受け取る。訪問者の IP は受け取らない
$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
```

ただし、この書き方では `$request->ip()` が全員プロキシの IP になる（偽られない代わりに、訪問者を見分けられない）。**ログインのロックの接続元の枠がサイト全体の枠になる**（上の「設定しない」と同じ）。接続元の IP で数える部品（ログインのロック・CSP の違反の報告の口・`IpBucket` で数える `Attempts` の枠）を使うアプリは、前段のアドレスの範囲を調べて `at:` に書く。

CSP の違反の報告の口（`docs/security.md`）の回数の制限も、同じ接続元の数え方なので同じ前提。

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

- 枠は2つ：①利用者と接続元の組（`account_max` 回 / `decay_seconds` 秒）、②利用者だけ（`password_account_max`=10 回 / `password_account_decay_seconds`=1800 秒）。②は、乗っ取ったセッションから接続元を替えながら試す総当たりを止める。
- どちらも失敗だけを数え、成功で空にする。上限なら `LoginLockedOut`（照合には進まない）。同じ利用者のログインは止まらない。
- ②は利用者ごとなので、本人が30分に10回間違えると、接続元を替えても窓が明けるまで確かめられない。

## メールアドレスの鍵（`EmailKey`）

- `NormalizedEmailKey`：PHP だけ。NFKC（全角・半角）をそろえ、前後の空白類を落とし、小文字にして sha1。**DB の照合順序と同じ範囲にはならない。**
- `MysqlCollationEmailKey($table, $column)`：MySQL 専用。列の照合順序の重みを DB に計算させ、`where email = ?` が同じ利用者に当たる範囲（大文字小文字・全角・無視される文字・末尾の空白）で枠をそろえる。MySQL でログインの照合を DB の照合順序に任せているなら、こちらを使う。計算できなければ `RuntimeException`（数える前に止まり、照合には進まない）。

## してはいけないこと

- 失敗の数えを自前で作らない（照合の後に足す形だと並列の要求が通り抜ける）。
- 照合が例外を投げても足した分は返らない（意図）。例外で数えを減らそうとしない。
- ミドルウェアの `throttle:login` と併用して二重に数えない。
- 逆プロキシの後ろで `trustProxies` を設定せずに使わない（全員が同じ接続元になる）。`at: '*'` にしない（Laravel 13.20.0 から、訪問者が IP を選べる）。
- アプリの `DB::transaction` の中で照合を呼ばない（`database` のキャッシュがアプリと同じ接続だと、巻き戻しで数えも巻き戻る。`docs/attempts.md`）。

## 限界

- 二段階認証を有効にした Fortify は、認証用の関数を2回続けて呼ぶ。失敗は1回目で終わるので1回しか数えず、成功は呼ぶたびに数えを戻すので何も残らない。
- 数えはキャッシュにある。キャッシュを消せば数えも消える。`APP_KEY` を入れ替えても消える。
- 診断の間に数えを外す `foundation.attempts.bypass` は、ログインのロックも外す（`production` では効かない。`docs/attempts.md`）。
- `file` のキャッシュでは同時の要求で足した分が消えうる。`array` では要求をまたいで数えない。`database`（新しいアプリの既定）か `redis` などを使う（`docs/attempts.md` の「限界」）。
