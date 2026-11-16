# 二段階の認証（認証アプリの確認コード）

名前空間 `Miraqceed\Foundation\TwoFactor`。認証アプリの6桁のコード（RFC 6238 の TOTP。HMAC-SHA1・30秒の刻み）を、**同じコードの使い回しと、同時の要求のすり抜けを許さずに**確かめる。呼んだときだけ働く。画面（QR の画像・登録と確かめの画面）は作らない。

- 手書きの認証のアプリ：`TwoFactor`（登録・確かめ・空にする・通った印）
- Fortify のアプリ：`FortifyTwoFactorProvider`（Fortify の確かめの差し替え）
- 間違いは、試みの数えの「続けた失敗」（`docs/attempts.md`）で数える。既定は 10 回でロック、ロックは 30 分（`foundation.attempts.consecutive`）

## いつ使うか

二段階の認証を組み込むとき。**TOTP を自前で書かない。** 自分で書くと次を踏みやすい。

- 確かめた後に `last_step` を読んで書く2段にすると、同じコードの2つの要求が両方通る
- 登録に使ったコードの刻みを残さないと、そのコードをすぐログインに使い回せる
- 窓のある数え（1分5回など）だけでは、時間をかければいつまでも試せる。前後1刻みを受けるので、1回の試みで3つのコードに当たる
- 登録の URI のラベルと issuer を URL エンコードしないと、名前に `:`・`&`・空白があると壊れる

## 手書きの認証のアプリ

### 1. 列を足す

```bash
php artisan vendor:publish --tag=foundation-two-factor-migrations
php artisan migrate
```

`users` に `two_factor_secret`（暗号にした秘密の鍵）・`two_factor_confirmed_at`・`two_factor_last_step`（最後に受けた刻み）を足す。無い列だけを足す（Fortify の2つの列が既にあっても落ちない）。列の名前は `foundation.two_factor.columns` で替えられる。

`TwoFactor` が読み書きする表は、渡したモデルの表（`getTable()`）。管理者を別の表（`admins` など）に持つアプリは、その表にも同じ列を足す（マイグレーションを写して表の名前を替える）。数えと通った印も、表ごとに分かれる。

**Fortify へ移るとき**：列の名前は Fortify と同じだが、値の形が違う（ここは `Crypt::encryptString`、Fortify は `encrypt()`〔serialize してから暗号〕）。Fortify に移すときは、鍵を読み戻して Fortify の形で暗号にし直す。

### 2. 登録

```php
use Miraqceed\Foundation\TwoFactor\Totp;
use Miraqceed\Foundation\TwoFactor\TwoFactor;

$twoFactor = app(TwoFactor::class);

// 鍵は確かめるまで保存しない（セッションに置くか、毎回同じに作れる形で作る）
$secret = Totp::generateSecret();                       // 160 ビット、Base32 で 32 字。80 ビット未満の鍵は confirm() が断る
$uri = $twoFactor->uri($user->email, $secret);          // issuer は foundation.two_factor.issuer（null なら app.name）
// $uri をアプリが選んだライブラリで QR にして見せる。鍵と QR を含む応答には Cache-Control: no-store を付ける

// 認証アプリが出したコードで確かめる。合えば、鍵（暗号にして）・確定の時刻・使った刻みを書く
// 入力は文字列だけを渡す（無い・配列のときは空の文字列にする。空や形の違う入力も、間違いに数える）
$code = is_string($request->input('code')) ? $request->input('code') : '';
if (! $twoFactor->confirm($user, $secret, $code)) {
    // 違うコード。間違いは続けた失敗に数える
}
```

まだ確定していないときだけ書く（2つのセッションが同時に登録しても、1つだけが確定する）。

### 3. ログインの後の確かめ

```php
use Miraqceed\Foundation\Attempts\TooManyAttempts;

try {
    $code = is_string($request->input('code')) ? $request->input('code') : '';
    if ($twoFactor->verify($user, $code)) {
        $twoFactor->pass($request, $user);   // セッションの番号を作り直し、通った印を置く
    }
} catch (TooManyAttempts $e) {
    // ロック中。コードは確かめていない。$e->retryAfter 秒後に再び試せる
}

// 守る画面の中間処理などで
$twoFactor->passed($request, $user);  // 今の鍵で通ったセッションか
```

- 打たれたコードは、空白（全角を含む）を除き、全角の数字を半角に直してから読む。6桁の数字でない入力（形の違う入力）も、間違いに数える
- 最後に受けた刻み以下のコードは受けない。刻みは「前の刻みより後のときだけ書く」条件つきの UPDATE で書くので、同じコードの2つの要求のうち1つだけが通る
- 通った印は、利用者と登録した鍵に結び付ける。鍵を登録し直すと、前の鍵で通ったセッションはまた聞かれる。通ってからの時間で区切るかは、アプリが決める
- ロックになると、出来事 `TwoFactorLocked`（利用者だけを持つ）が出る。1 回のロックにつき 1 回だけ出る（並んだ総当たりでも）。並んだ正しいコードが通ってロックが空になったら、次のロックでもまた出る。運営に知らせるなどは、アプリが聞き手を書く

### 4. 空にする

`$twoFactor->reset($user)` が3つの列を空にする（登録し直し・アカウントを外すとき）。列を別々に空にしない（1か所の直し忘れで古い値が残る）。

## Fortify のアプリ

```php
// app/Providers/AppServiceProvider.php の register()
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Miraqceed\Foundation\TwoFactor\FortifyTwoFactorProvider;

$this->app->singleton(TwoFactorAuthenticationProvider::class, FortifyTwoFactorProvider::class);
```

```php
// config/fortify.php：two-factor の回数の制限を外す（続けた失敗で数えるので、二重に数えない）
'limiters' => [
    'login' => /* … */,
    'two-factor' => null,
],
```

- Fortify の元の確かめは、使ったコードの印をキャッシュから読んでから確かめ、書き込む。同時の2つの要求で、同じコードが両方通る（Fortify v1.42.0 で、試しで起こして確かめた）。印の鍵もコードだけで、利用者によらない
- 差し替えは、合った刻みの印を `Cache::add`（原子的）で立てられたときだけ通す。印の鍵は秘密の鍵と刻みの HMAC（鍵もコードもキャッシュの鍵に入れない）。印はそのコードが受けられる間より長く残る。通ったら、窓の中のそれより前の刻み（刻み − 2 × window から）にも印を置く（先の刻みのコードで通った後も、前の刻みのコードを受けない）
- 間違いは、秘密の鍵ごとに続けた失敗で数える。ロック中は、確かめずに `code` の欄の誤り（Fortify の `auth.throttle` の文）で断る。JSON の要求は 429、画面のフォームは前の画面へ戻り、誤りが `code` に入る（Laravel の検証の誤りと同じ）。登録の確かめ（`two-factor.confirm`）のときは、Fortify と同じく `confirmTwoFactorAuthentication` の袋に入る
- 登録の確かめ（`ConfirmTwoFactorAuthentication`）も、同じ差し替えを通る
- Fortify の窓（`fortify-options.two-factor-authentication.window`）を守る

## してはいけないこと

- TOTP を自前で書かない
- 確かめた後に `last_step` を読んで書く2段にしない（条件つきの UPDATE で1回）
- 登録に使ったコードの刻みを捨てない
- 間違いを窓のある数えだけで数えない
- 秘密の鍵を平文で置かない（DB・ログ・キャッシュの鍵・例外の文）
- 確かめる前の鍵を、利用者の表に保存しない

## 限界

- 予備のコード（recovery codes）は対象の外。Fortify は持っている。手書きのアプリは、アプリが作る
- Fortify の差し替えは、刻み t で通ってから前の刻みに印を置くまでの間に、前の刻みの別の正しいコードがほぼ同時に届くと、それも通りうる（キャッシュには「より大きいときだけ書く」原子的な操作がない）。完全な守りが要るなら、手書きの `TwoFactor`（条件つきの UPDATE）を使う
- 数えと Fortify の差し替えの印はキャッシュにある。キャッシュを消すと消える。`array` のキャッシュでは要求をまたいで効かない（`docs/attempts.md` の「限界」）
- `APP_KEY` を入れ替えると、数え・印・通った印が消える（鍵の HMAC に `APP_KEY` を使う）
- `reset()` は続けた失敗の数えとロックを空にしない（ロックの時間で明ける）
