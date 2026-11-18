# メールアドレスの入力の整え方

名前空間 `Miraqceed\Foundation\Mail`。利用者が打ったメールアドレスを、**保存する値**と**比べの形**にそろえる。呼んだとき・入れたときだけ働く。設定・DB・DNS を読まない。

- `EmailInput::normalize()`：保存する値（送る宛先・表示）
- `EmailInput::canonical()`：比べの形（一意の列・探すとき）
- `NormalizeEmailInput`：入力の欄を、検証の前に `normalize()` でそろえるミドルウェア（アプリが入れる）
- `Login\CanonicalEmailKey`：回数の制限・押さえを、比べの形と同じ範囲にする鍵
- `NotDisposableEmail`・`DisposableEmailDomains`：使い捨てのメールのドメインを断る規則（一覧のファイルはアプリが置く。下の 5）

届かないドメイン（`example.com`・`.invalid` など）の判定は `ReservedEmailDomains`（`docs/reserved-email-domains.md`）。

## いつ使うか

利用者がメールアドレスを打つ口（登録・ログイン・再設定・変更・招待）を持つアプリ。自分で書くと次を踏む。

- 日本語の入力のまま打った全角のアドレス（`ｔａｒｏ@example.com`・`taro@ｅｘａｍｐｌｅ.com`）は、Laravel の `email` の規則（`email:rfc,strict` も）を**通る**。全角のまま保存され、確認のメールは届かず、同じ人が正しいアドレスで登録し直しても「使われている」にならない
- `taro@example。com`（句点）も規則を通る。`mb_convert_kana()` でも NFKC でも、句点は点にならない
- 大文字・小文字の違いで、同じ人のアカウントが2つできる（Google などの外部のログインは小文字で返す）。Gmail は点と `+` 以降を区別しないので、`taro.yamada+news@gmail.com` でも2つ目を作れる
- Gmail のそろえ方を、ほかのドメインに広げると、別の人を同じとみなす（Google Workspace の独自ドメインは点を区別する）
- 全角を半角にするとき、壊れた UTF-8 を直してしまう（検証で断るべき値が通る）
- `email` だけをそろえ、`email_confirmation` をそろえないと、正しく打った人が `confirmed` で断られる

## そろえ方

| | `normalize()`（保存する値） | `canonical()`（比べの形） |
|---|---|---|
| 全角の英数字・記号（`＠`・`＿`・`－`・`＋`・`．`） | 半角 | 半角 |
| 前後の空白（全角・ノーブレークスペース・ゼロ幅） | 落とす | 落とす |
| ドメインの句点（`。`・`｡`）と末尾の点 | 点にして、末尾の点を落とす | 同じ |
| ドメインの大文字 | ASCII の英字だけ小文字（日本語のドメインは打ったまま） | 全体を小文字（非 ASCII も） |
| ローカル部の大文字 | **打ったまま** | 小文字 |
| `gmail.com`・`googlemail.com` の点と `+` 以降 | 打ったまま | 落とし、`@gmail.com` にそろえる |
| 壊れた UTF-8 | 変えない | 変えない |

```php
use Miraqceed\Foundation\Mail\EmailInput;

EmailInput::normalize(' ｔａｒｏ＠Example。COM。');   // 'taro@example.com'
EmailInput::normalize('Taro.Yamada@Example.com');      // 'Taro.Yamada@example.com'
EmailInput::canonical('Taro.Yamada+news@GoogleMail.com'); // 'taroyamada@gmail.com'
```

- ローカル部の大文字・小文字は、規格では受け手が区別してよい。保存する値（送る宛先）では変えず、比べの形でだけそろえる
- 形の確かめ（`@` の数・ラベル）はしない。`email` の規則で断る

## 1. 入力の欄をそろえる

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: \Miraqceed\Foundation\Mail\NormalizeEmailInput::class);
    $middleware->api(append: \Miraqceed\Foundation\Mail\NormalizeEmailInput::class); // API でも打たせるなら
})
```

- 当てる欄は `foundation.mail.input_fields`（既定 `['email']`）の名前。入れ子（`rows.0.email`）と、それぞれの `<名前>_confirmation` も整える。JSON の本文も同じ
- `new_email`・`invite_email` など別の名前の欄は足す：`'input_fields' => ['email', 'new_email']`（`new_email_confirmation` も整う）
- 文字列でない値・壊れた UTF-8 は変えない。設定が名前の文字列の配列でなければ `InvalidArgumentException`
- **入れただけでは働かない。** パッケージは全体に入れない
- ミドルウェアを入れない口（コマンド・外部の連携のコールバック・CSV の取り込み）は、助けを直に呼ぶ：

  ```php
  // FormRequest
  protected function prepareForValidation(): void
  {
      $this->merge(['email' => EmailInput::normalize((string) $this->input('email'))]);
  }

  // 外部のログイン（Socialite）で受け取ったアドレス
  $email = EmailInput::normalize($socialiteUser->getEmail());
  ```

## 2. 保存する（2つの列）

```php
// マイグレーション
Schema::table('users', function (Blueprint $table) {
    $table->string('email_canonical')->unique();
});

// モデル：保存するたびに比べの列を作る（email は NormalizeEmailInput か normalize() でそろえた値）
protected static function booted(): void
{
    static::saving(function (User $user) {
        $user->email_canonical = EmailInput::canonical($user->email);
    });
}
```

- **探すときは比べの列で**：ログイン・再設定・外部の連携の照合・管理のコマンド

  ```php
  $user = User::where('email_canonical', EmailInput::canonical($request->input('email')))->first();

  // Fortify のログイン
  Fortify::authenticateUsing(function (Request $request) {
      $user = User::where('email_canonical', EmailInput::canonical((string) $request->input('email')))->first();

      return $user && Hash::check((string) $request->input('password'), $user->password) ? $user : null;
  });

  // 再設定のリンク（Password::sendResetLink は、渡した列で探す）
  Password::sendResetLink(['email_canonical' => EmailInput::canonical($request->input('email'))]);
  ```

- `email` の列の一意の索引は外してよい（一意は比べの列が守る）。送る宛先は `email`
- **1つの列だけのアプリ**（小さいアプリ）は、`email` に `canonical()` を入れてもよい。探すのも同じ列で済む。送る宛先も小文字になり、Gmail の別名は `@gmail.com` の形になる（Gmail の側で同じ受信箱に届く）

## 3. 回数の制限・押さえの範囲

比べの列と、ログインのロック・メールアドレスの変更の押さえで「同じアドレス」とみなす範囲をそろえる。そろえないと、ロックは別の人とみなし、一意の索引は同じ人とみなす。

```php
use Miraqceed\Foundation\Login\CanonicalEmailKey;
use Miraqceed\Foundation\Login\LoginLock;

$lock = new LoginLock(new CanonicalEmailKey);

// config/foundation.php
'email_changes' => [
    'email_key' => \Miraqceed\Foundation\Login\CanonicalEmailKey::class,
    'availability' => \App\Accounts\CanonicalEmailAvailability::class,
],
```

メールアドレスの変更（`EmailChanges`。`docs/accounts.md`）の空きの判定は、比べの列で見る：

```php
use Illuminate\Database\Eloquent\Model;
use Miraqceed\Foundation\Accounts\EmailAvailability;
use Miraqceed\Foundation\Mail\EmailInput;

final class CanonicalEmailAvailability implements EmailAvailability
{
    public function isTaken(string $email, Model $except): bool
    {
        return $except->newQueryWithoutScopes()
            ->where('email_canonical', EmailInput::canonical($email))
            ->whereKeyNot($except->getKey())
            ->exists();
    }
}
```

## 4. 既にあるアカウントを移す

1. 比べの列を null を許して足し、すべての行に `canonical()` を書く（`chunkById` で）
2. 同じ比べの形の行を探す：`select email_canonical, count(*) from users group by email_canonical having count(*) > 1`。見つかったら、本人に確かめて1つにする（自動で消さない・つなげない）
3. 重なりがなくなってから、null を許さず一意の索引を付ける
4. `email` の列も `normalize()` で書き直す（全角で入っていた行は、確認のメールが届いていない。確認の印を見直す）

Gmail のそろえ方を後から入れると、`taro.yamada@gmail.com` と `taroyamada@gmail.com` で別々に作られていたアカウントが、2 で重なりとして出る。

## 5. 使い捨てのメールアドレスを断る

```php
use Miraqceed\Foundation\Mail\NotDisposableEmail;

'email' => ['required', 'email', new NotDisposableEmail],
```

```php
// config/foundation.php
'mail' => [
    'disposable_domains' => resource_path('data/disposable-email-domains.txt'),
],
```

- **一覧のファイルはアプリが置く。** パッケージは一覧を持たず、取りに行かない。公開の一覧（例：GitHub の `disposable-email-domains/disposable-email-domains` の `disposable_email_blocklist.conf`、CC0）を写し、CI か schedule で取り直す（一覧は増え続ける。取り直さないと古くなる）
- 形：1行に1つのドメイン。`#` で始まる行と空の行は飛ばす。大文字・前後の空白・前後の点はそろえる
- 1つのプロセスで1回だけ読む（容器の singleton）。設定の道が変われば読み直す
- 判定の前に、アドレスを `EmailInput::normalize()` でそろえる（全角・`＠`・句点・末尾の点・大文字）。日本語のドメインは xn-- の形で比べる
- **下位のドメインも当たる**（一覧に `mailinator.com` があれば `a.mailinator.com` も）。点のない名前（`jp` など）の行では当てない
- 設定がない・ファイルが読めないときは `LogicException`（黙ってすべてを通さない）
- 文は「使い捨てのメールアドレスは使えません。普段お使いのメールアドレスを入れてください。」（`foundation::validation.disposable_email`。`en` は英語）
- 登録・メールアドレスの変更の欄に付ける。**ログインの欄には付けない**（登録済みのアドレスには「メールアドレスかパスワードが違う」と出す）
- 一覧に誤って入った正しいドメインを外す口は持たない。一覧のファイルから消す

## してはいけないこと

- 全角のアドレスを、検証を通ったからといってそのまま保存しない
- `email` だけをそろえて、`email_confirmation` をそろえない（ミドルウェアは両方そろえる）
- Gmail のそろえ方（点・`+`）を、ほかのドメインに広げない
- 送る宛先に `canonical()` の値を使わない（2つの列のとき）。ローカル部の大文字・Gmail の別名が変わる
- 壊れた UTF-8 を直してから検証しない

## 限界

- そろえるのは、打ち間違い・日本語の入力で起きる違いだけ。`gmail.con` などの綴りの誤りは直さない
- ローカル部の大文字・小文字を区別する受け手には、2つの列の形で正しく届くが、比べの形は同じになる（同じ人とみなす）。規格の上ではありうるが、実際のメールの事業者で区別するものは聞かない
- Fortify の再設定（`/reset-password`）は `email` の列で利用者を探す。ローカル部の大文字が違う入力は、大文字・小文字を区別する DB（PostgreSQL）で見つからない。気になるアプリは、1つの列の形にするか、再設定の口を比べの列で探す形に替える
