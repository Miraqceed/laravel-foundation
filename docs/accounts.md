# アカウントの乗っ取りの守り

名前空間 `Miraqceed\Foundation\Accounts`。パスワードかセッションを盗まれても、持ち主が締め出されないようにする。呼んだときだけ働く。経路・画面・メールは作らない。アプリの口（自前の作り・Fortify）から呼ぶ。

- パスワードの変更・再設定の後のセッション：`PasswordSessions`
- メールアドレスの変更と戻すリンク：`EmailChanges`・`Rules\NotHeldEmail`（表を作る。既定で無効）
- 確認のリンク・アドレスのそろえ方・アカウントの引き取り：クラスはなく、決まりだけ

## パスワードの変更・再設定の後のセッション

### いつ使うか

パスワードを変える口と、再設定の口を持つアプリ。Laravel の既定と Fortify の雛形では、次が守られない。

- パスワードの再設定・変更の後も、ほかの端末のセッションが残る（`auth.session` を入れていなければ）。盗んだ人は、パスワードを変えられた後もログインしたまま
- 盗まれた「ログインしたままにする」（remember）の Cookie で、また入れる
- 自分で `Auth::logoutOtherDevices($password)` を呼ぶと、渡した平文でパスワードをハッシュし直して保存する。新しいパスワードを書いた後に呼ぶと、ハッシュの計算と書き込みが2回になる

`PasswordSessions` は次をする。

| | `afterChange($request, $user)`（パスワードを変えた後。ログイン中の本人） | `afterReset($user)`（再設定の後。ログインしていない要求） |
|---|---|---|
| remember のトークン | 作り直す（盗まれた Cookie が効かなくなる） | 作り直す |
| ほかのセッション | 消す（セッションが `database` のとき） | すべて消す（セッションが `database` のとき） |
| この端末 | 入り直させる：セッション ID・セッションのパスワードのハッシュを新しくする。remember の Cookie は持っていたときだけ新しいトークンで作り直す | 触らない |
| 戻り値 | 消したほかのセッションの数 | 消したセッションの数 |

- パスワードのハッシュは計算しない（`logoutOtherDevices()` を使わない）
- 入り直しは `Auth::login()` なので、`Login` の出来事が出る（ログインの記録の聞き手を持つアプリは、ここでも1件増える）
- `afterChange()` で、この端末に入り直させられないとき（要求にセッションがない・ガードがセッションのガードでない・ログイン中の利用者が `$user` でない。管理者がほかの人のパスワードを変えたときなど）は、`afterReset()` と同じにする。管理者のログインには触らない
- ガードは `foundation.accounts.guard`（既定 `web`）。セッションの表は `session.table`、接続は `session.connection`

### セッションは `database` か、`auth.session` が要る

ほかの端末のセッションの行を消せるのは、`SESSION_DRIVER=database` のときだけ。

- **`database`**：`sessions` の表の `user_id` の行を消す。ほかの端末は次の要求でログインしていない
- **`database` でない**（`file` など）：行は消せない。`afterChange()`・`afterReset()` は 0 を返し、止めない（パスワードは書いた後なので、失敗にしない）。web のグループに `auth.session`（`AuthenticateSession`）を入れれば、ほかの端末は、パスワードのハッシュが合わなくなった次の要求でログアウトする

  ```php
  // bootstrap/app.php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->authenticateSessions();
  })
  ```

どちらもないと、パスワードを変えても、ほかの端末のセッションは残る（remember のトークンの作り直しは効く）。`php artisan foundation:deploy-check` の `password_sessions` が `info` で知らせる（`docs/deploy-check.md`）。

### 自前の口から呼ぶ

```php
use Miraqceed\Foundation\Accounts\PasswordSessions;

// パスワードを変える口（今のパスワードを確かめた後）
$user->forceFill(['password' => Hash::make($request->string('password'))])->save();
app(PasswordSessions::class)->afterChange($request, $user);

// 再設定の口（Password::reset() の閉包の中、またはその後）
app(PasswordSessions::class)->afterReset($user);
```

`afterChange()` は、パスワードを書いた後の同じ要求の中で呼ぶ（この端末のセッションと Cookie を作り直す）。キューに載せない。

### Fortify から呼ぶ

パッケージは聞き手を登録しない（入れただけで挙動を変えない）。アプリが登録する。

```php
// app/Providers/AppServiceProvider.php の boot()
use Illuminate\Support\Facades\Event;
use Miraqceed\Foundation\Accounts\Fortify\EndSessionsAfterChange;
use Miraqceed\Foundation\Accounts\Fortify\EndSessionsAfterReset;

Event::listen(\Illuminate\Auth\Events\PasswordReset::class, EndSessionsAfterReset::class);
Event::listen(\Laravel\Fortify\Events\PasswordUpdatedViaController::class, EndSessionsAfterChange::class);
```

- `PasswordReset` は、Fortify の再設定（`/reset-password`）も、Laravel の `Password::reset()` で書いた雛形も出す
- `PasswordUpdatedViaController` は、Fortify のパスワードの変更（`/user/password`）が出す
- 聞き手はキューに載せない（`ShouldQueue` を付けない）

### してはいけないこと

- パスワードを変えた後に `Auth::logoutOtherDevices($password)` を呼ばない（ハッシュの計算と書き込みが2回。`database` のセッションの行も消えない）
- セッションの行だけを消して、remember のトークンを作り直さないままにしない（盗まれた Cookie で入り直せる）
- この端末のセッション ID を作り直さないままにしない（盗まれたセッション ID がこの端末と一緒に生き残る）

### 限界

- セッションが `database` でなく、`auth.session` もないと、ほかの端末のセッションは残る（上）
- `auth.session` は、そのミドルウェアを通る要求でだけ締め出す。web のグループに入れずに一部の経路にだけ付けると、付いていない経路では残る
- API のトークン（Sanctum など）は消さない。アプリが同じ口で消す

## メールアドレスの変更と戻すリンク

### いつ使うか

利用者がメールアドレスを変えられるアプリ。Laravel の既定と Fortify・Breeze の雛形では、変更が**その場で切り替わる**（`email` を書き、`email_verified_at` を空にして、新しいアドレスに確認のメールを送るだけ）。セッションかパスワードを盗んだ人が、アドレスを自分のものに変え、再設定でパスワードも取ると、持ち主は戻れない。古いアドレスには何も届かない。

`EmailChanges` は次をする。

- **確かめてから切り替える**：新しいアドレスに届いたリンクを、頼んだ本人がログインして押したときだけ切り替える
- **古いアドレスへ戻すリンク**（既定 7日）：押すと古いアドレスに戻し、乗っ取った人が持つもの（パスワード・セッション・remember・再設定のトークン・次の頼み）を消す。アプリの差し込みで API キー・外部の連携も消す
- **押さえ**：戻せる間は、古いアドレスをほかの人に登録・変更させない（`NotHeldEmail`）
- 同時の要求（2つのタブ・2つのリンク）でも1回だけ切り替える。利用者の行 → 変更の行の順に握り、握った後に比べ直す

### 1. 表を作る

```php
// config/foundation.php
'email_changes' => [
    'enabled' => true,
],
```

```bash
php artisan migrate
```

`vendor:publish --tag=foundation-email-change-migrations` で写してもよい。表 `foundation_email_changes` は既定の接続に作る。**利用者のモデルも既定の接続に置く**（切り替え・戻すは、利用者の行と変更の行を1つのトランザクションで書く）。古い行は `model:prune` で消す（アプリが schedule に入れる）：

```php
Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Accounts\EmailChange::class]])->daily();
```

### 2. 頼む口

```php
use Miraqceed\Foundation\Accounts\EmailChanges;
use Miraqceed\Foundation\Accounts\Rules\NotHeldEmail;

// 経路：['auth', 'password.confirm']。回数は Attempts で利用者ごとに数える（他人のアドレスへメールを送り続けさせない）
$validated = $request->validate([
    'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($request->user()), new NotHeldEmail($request->user())],
]);

$requested = app(EmailChanges::class)->request($request->user(), $validated['email']);

Mail::to($requested->newEmail)->send(new ConfirmEmailChange(
    route('email-change.confirm', [$requested->id, $requested->token]),
    $requested->expiresAt,
));
```

- 前のまだ確かめていない頼みは取り消す（頼みは1つだけ）
- `request()` は新しいアドレスを判定しない。形・`unique`・`NotHeldEmail` はアプリの検証で見る（確かめるときに、パッケージが比べ直す）
- トークンは戻り値にだけある。ログ・flash・監査の記録に入れない

### 3. 確かめる口（新しいアドレスに届いたリンク）

```php
// GET：表示だけ（メールのスキャナーが開いても動かない）。経路に auth を付ける
Route::get('/email-change/{id}/{token}', fn ($id, $token) => view('email-change.confirm', compact('id', 'token')))
    ->middleware('auth')->name('email-change.confirm');

// POST：確かめる
Route::post('/email-change/{id}/{token}', function (Request $request, string $id, string $token) {
    $result = app(EmailChanges::class)->confirm($id, $token, $request->user());

    if ($result->confirmed()) {
        Mail::to($result->oldEmail)->send(new EmailChanged(
            route('email-change.undo', [$id, $result->undoToken]),
            $result->undoExpiresAt,
        ));

        return redirect('/settings')->with('status', 'メールアドレスを変えました');
    }

    return back()->withErrors(['email' => match ($result->status) {
        EmailChangeStatus::Expired => 'リンクの期限が切れています。もう一度変更を頼んでください',
        EmailChangeStatus::Used => 'このリンクは使われています',
        EmailChangeStatus::Taken, EmailChangeStatus::Held => 'このメールアドレスは使えません',
        default => 'このリンクは使えません',
    }]);
})->middleware(['auth', 'throttle:10,1']);
```

- **頼んだアカウントにログインしているときだけ切り替える**。ログインしていなければ、`auth` がログインの画面へ送り、ログインの後にリンクへ戻す。戻り先（`url.intended`）を自前で持つなら、だれのリンクかと一緒に持つ（別の人のログインの後に使わない）
- 断りの理由は `EmailChangeStatus`。トークンが合わないとき（`invalid`）は理由を分けない。本人でない人（`not_requester`）には、切れた・使ったも見せない
- 切り替えると、`email_verified_at`（`MustVerifyEmail` のモデル）を今にし、古いアドレスの再設定のトークンを消す。コミットの後に `Events\EmailChangeConfirmed` が出る（決済の顧客のアドレスなどは、アプリが聞き手で直す）

### 4. 戻す口（古いアドレスに届いたリンク）

```php
// GET は表示だけ。ログインは求めない
Route::post('/email-change/{id}/undo/{token}', function (Request $request, string $id, string $token) {
    $result = app(EmailChanges::class)->undo($id, $token);

    if ($result->undone()) {
        // この要求がその利用者でログインしていると、表の行を消しても、要求の終わりにセッションが書き戻される
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/forgot-password')->with('status', 'メールアドレスを元に戻しました。パスワードを設定し直してください');
    }

    return back()->withErrors(['email' => $result->status === EmailChangeStatus::Expired
        ? 'リンクの期限が切れています。運営に問い合わせてください'
        : 'このリンクは使えません']);
})->middleware('throttle:10,1')->name('email-change.undo');
```

戻すと、パッケージは必ず次をする（1つのトランザクション）。

- アドレスを古いアドレスに戻す。その後の変更（B → C）もたどって戻す
- パスワードを乱数の値のハッシュに替える（持ち主は古いアドレスへの再設定で入り直す）
- remember のトークンを作り直し、すべてのセッションを消す（`PasswordSessions::afterReset()`）
- たどったすべてのアドレスの再設定のトークンを消す。まだ確かめていない頼みを取り消す
- アプリの差し込み `AccountLockdown::lockdown()` を呼ぶ。投げたら全部巻き戻る
- コミットの後に `Events\EmailChangeUndone` が出る（運営に知らせる）

### 5. アプリの差し込み（API キー・外部の連携）

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Miraqceed\Foundation\Accounts\AccountLockdown;

final class AppLockdown implements AccountLockdown
{
    /** 確かめたときに残す。秘密を入れない */
    public function snapshot(Model&Authenticatable $user): array
    {
        return ['social_ids' => $user->socialAccounts()->pluck('id')->all()];
    }

    /** 戻すときに、同じトランザクションの中で呼ばれる */
    public function lockdown(Model&Authenticatable $user, array $snapshot): void
    {
        $user->tokens()->delete();                                                     // Sanctum の API キー
        $user->socialAccounts()->whereNotIn('id', $snapshot['social_ids'] ?? [])->delete(); // 後から連携したもの
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save(); // 乗っ取った人が登録し直した二段階の認証
    }
}
```

```php
// config/foundation.php
'email_changes' => [
    'enabled' => true,
    'lockdown' => \App\Accounts\AppLockdown::class,
],
```

### 6. 押さえを登録・変更の検証に足す

```php
'email' => ['required', 'email', new NotHeldEmail],                    // 登録
'email' => ['required', 'email', new NotHeldEmail($request->user())],  // 変更（本人の押さえは数えない）
```

- 「同じアドレス」の比べは `EmailKey`（既定 `NormalizedEmailKey`：全角・半角・大文字・前後の空白をそろえる）。Gmail の点と `+` もそろえるアプリは、自分の `EmailKey` を `foundation.email_changes.email_key` に渡す
- 文は1つ（`foundation::validation.email_held`）。押さえの理由を出さない

### 7. パスワードの再設定・変更の後

まだ確かめていない頼みを取り消す（パスワードを知る他人の頼みを生かさない）。

```php
app(EmailChanges::class)->cancelPending($user);
```

### Fortify のアプリ

Fortify のプロフィールの更新（`UpdateUserProfileInformation`）は、メールアドレスをその場で書く。アドレスの欄を、書く代わりに `request()` に替える。

```php
// app/Actions/Fortify/UpdateUserProfileInformation.php
public function update(User $user, array $input): void
{
    Validator::make($input, [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id), new NotHeldEmail($user)],
    ])->validateWithBag('updateProfileInformation');

    if ($input['email'] !== $user->email) {
        $requested = app(EmailChanges::class)->request($user, $input['email']);
        Mail::to($requested->newEmail)->send(new ConfirmEmailChange(route('email-change.confirm', [$requested->id, $requested->token])));
    }

    $user->forceFill(['name' => $input['name']])->save();
}
```

確かめる口・戻す口は、上の 3・4 をアプリに足す（Fortify は持たない）。今のパスワードの確かめは `password.confirm` を経路に付ける。

### 設定

| 設定 | 既定 | |
|---|---|---|
| `foundation.email_changes.enabled` | `false` | 真のときだけ表のマイグレーションを読み込む |
| `confirm_minutes` | `60` | 確認のリンクの期限 |
| `undo_days` | `7` | 戻すリンクの期限。押さえも同じ間 |
| `email_column` | `email` | 利用者のモデルのメールアドレスの列 |
| `email_key` | `null`（`NormalizedEmailKey`） | 押さえの比べ |
| `availability` | `null`（`ModelEmailAvailability`） | アドレスがほかのアカウントに使われているか。既定はモデルの表の email の列 |
| `lockdown` | `null`（何もしない） | `AccountLockdown` のクラス |
| `password_broker` | `null`（`auth.defaults.passwords`） | 再設定のトークンを消すブローカー。`false` なら消さない（再設定を持たないアプリ） |
| `prune_days` | `7` | もう使えない行を消すまでの日数 |

設定のクラスが無い・契約を満たさないときは、黙って既定に寄せずに `InvalidArgumentException` で止める。

### してはいけないこと

- メールアドレスを、確かめる前に書かない（Fortify・Breeze の雛形のまま使わない）
- 確かめる口・戻す口を GET で動かさない（メールのスキャナーが開く）
- 確かめる口から `auth` を外さない（届いたリンクを押しただけの人のアドレスが、他人のアカウントに載る）
- 頼む口に、今のパスワードの確かめ（`password.confirm`）と回数の数えを付けずにおかない
- トークンをログ・flash・監査の記録に入れない

### 限界

- **アドレスの変更は、管理者の操作も含めて `EmailChanges` を通す。** 管理の画面・コマンド・DB でアドレスを直に書くと、前の変更とのつながりが切れ、それまでの戻すリンクが `changed` になって、持ち主が戻せなくなる。管理者が直すときも `request()` と `confirm()` を通すか、戻せる間（`undo_days`）が過ぎてから直す
- 押さえは、登録・変更の検証に `NotHeldEmail` を足したときだけ効く。足していない口（招待・管理の画面）からは、古いアドレスで入れる（戻すときは `taken` で断る）
- 外部の連携・API キーは、差し込み（`AccountLockdown`）を書かなければ消えない
- 戻すリンクは古いアドレスに届く。古いアドレスのメールも盗まれていれば守れない
- 戻せるのは `undo_days` の間だけ。その後は運営が本人を確かめて直す

## 確認のリンク・アドレスのそろえ方・アカウントの引き取り

クラスは足さない。自分で書くと踏む穴と、その決まり。

### 確認のリンクは Laravel の既定を使う

Laravel の既定の確認のリンク（`VerifyEmail`）は、利用者の id と、アドレスの `sha1`（`hash`）を持つ。確かめる側（`EmailVerificationRequest`）は、**ログイン中の利用者**の id と、**今のアドレス**の `sha1` を比べる。Fortify・Breeze の経路は `auth`・`signed`・`throttle` を付けている。そのため、既定のままなら次の穴はない。

- ログインしていない人が、リンクを押しただけで確認済みにする
- アドレスを変えた後で、古いアドレスへ送ったリンクが効く

決まり：

- 確認の経路から `auth` を外さない。`EmailVerificationRequest` を自前の検証に替えない
- リンクの行き先は `APP_URL` から作る（`foundation.mail.links_from_app_url`。`docs/mail-safety.md`）。この部品は `VerifyEmail::createUrlUsing()` を差し替えない
- 既定の `sha1` には鍵がない。URL（アクセスの記録・履歴・転送）を見た人は、手元の候補のアドレスと突き合わせて「このアドレスの確認のリンク」と分かる。漏れるのはそれだけで、乗っ取りにはつながらない（署名と `auth` が守る）

### 自前の確認のリンクを書くとき

既定を使えないとき（確認を別の画面で受ける・メールを丸ごと作る）は、次の2つを守る。

1. **ログイン中の本人だけを確認済みにする。** リンクの利用者の id と、`$request->user()` が同じときだけ印を付ける。ログインしていなければ、ログインの後にリンクへ戻す（下の「戻り先」）
2. **リンクをアドレスに結び付ける。** アドレスの**鍵つきの指紋**をリンクに入れ、確かめるときに**今のアドレス**の指紋と比べる。アドレスを変えた後は、古いリンクが効かない。鍵は `APP_KEY` から、用途の名前を入れて作る（同じ鍵の HMAC をほかの用途の値と取り違えない）。鍵を替えた後も、出したリンクが期限まで効くよう、前の鍵（`APP_PREVIOUS_KEYS`）でも比べる

```php
use Illuminate\Support\Facades\URL;

final class EmailFingerprint
{
    public static function of(string $email, ?string $key = null): string
    {
        return hash_hmac('sha256', 'email-verification|'.$email, $key ?? app('encrypter')->getKey());
    }

    public static function matches(string $email, mixed $given): bool
    {
        if (! is_string($given)) {
            return false;
        }
        foreach ([app('encrypter')->getKey(), ...app('encrypter')->getPreviousKeys()] as $key) {
            if (hash_equals(self::of($email, $key), $given)) {
                return true;
            }
        }

        return false;
    }
}

// 作る（署名は絶対の URL。docs/mail-safety.md）
$url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
    'id' => $user->getKey(),
    'hash' => EmailFingerprint::of($user->getEmailForVerification()),
]);

// 確かめる（経路に auth と signed）
abort_unless($request->user()->getKey() == $request->route('id'), 403);
abort_unless(EmailFingerprint::matches($request->user()->getEmailForVerification(), $request->route('hash')), 403);
$request->user()->markEmailAsVerified();
```

メールアドレスの変更の確かめ（`EmailChanges::confirm()`）は、乱数のトークンと本人のログインで守るので、指紋は要らない。

### ログインの後の戻り先

`auth` は、ログインしていない人をログインの画面へ送るとき、元の URL を `url.intended` に置き、ログインの後にそこへ戻す。

- 既定の確認の経路では、別の人がログインして戻っても、`EmailVerificationRequest` が 403 にする（害は小さい）
- メールアドレスの変更の確かめでは、`confirm()` が `not_requester` を返す
- **戻り先を自前で持つなら、だれのリンクかと一緒に持つ。** ログインした人がリンクの持ち主でなければ、戻り先を捨てる（別の人のログインの後に、その人のセッションでリンクを動かさない）

### メールアドレスをそろえて保存し、そろえて探す

同じアドレスを違う形で持つと、Google などの外部のログインが小文字で返したときにアカウントが2つできる。一意の索引も、形が違えば同じアドレスを通す。DB によって比べ方も違う（MySQL の既定の照合順序は大文字・小文字を区別せず、PostgreSQL は区別する）。

そろえ方は `Mail\EmailInput` と `Mail\NormalizeEmailInput`（`docs/email-input.md`）を使う。

- **保存する値**（`email`。送る宛先・表示）：`EmailInput::normalize()`。全角を半角、前後の空白、ドメインの句点と末尾の点、ドメインの英字の大文字をそろえる。ローカル部の大文字・小文字は打ったまま。検証の前に `NormalizeEmailInput` で入力の欄ごとそろえる
- **比べの形**（`email_canonical` などの一意の列）：`EmailInput::canonical()`。全体を小文字にし、Gmail だけ点と `+` 以降を落とす。登録・変更・ログイン・再設定・外部の連携の照合は、この列で探す
- **書くとき（登録・変更・外部の連携で作るとき）も、探すとき（ログイン・再設定・外部の連携の照合）も**同じに整える。片方だけでは合わない
- 一意の索引は、比べの列に付ける
- 回数の制限・押さえで「同じアドレス」とみなす範囲（`EmailKey`。`docs/login.md`）も、比べの列と同じ範囲にする：`Login\CanonicalEmailKey`
- メールアドレスの変更（`EmailChanges`）の空きの判定は、`EmailAvailability` を差し替えて比べの列で見る（例は `docs/email-input.md`）

### 未確認のアカウントを引き取るとき

他人が先に本人のアドレスで登録しておき（確認はしない）、パスワードを決め、外部の連携や二段階の認証を付けておく。後で本人が再設定でアカウントを引き取っても、他人のセッション・連携が残ると、他人も入れる。

- 再設定の後は、すべてのセッションを消し、remember のトークンを作り直す（`PasswordSessions::afterReset()`。Fortify なら `EndSessionsAfterReset` を登録する）
- **再設定のときにアドレスが未確認だったアカウントは、他人が作ったものも消す**：外部の連携・API キー・二段階の認証・まだ確かめていないアドレスの変更（`EmailChanges::cancelPending()`）。メールアドレスの変更の戻すときと同じ差し込み（`AccountLockdown`）を使うと1か所で済む
- **未確認だったかは、再設定の処理より前に読む。** 再設定のリンクでアドレスを確かめたとして、再設定の中で `markEmailAsVerified()` を呼ぶアプリでは、パスワードを書いた後に読むといつも確認済みになり、他人の連携が残る

  ```php
  // 再設定の前に読む（確認済みにする処理より前）
  $wasUnverified = ! $user->hasVerifiedEmail();

  // …パスワードを書く（確認済みにするなら、ここで markEmailAsVerified()）…

  // パスワードを書いた後（同じ要求の中で）
  if ($wasUnverified) {
      app(\App\Accounts\AppLockdown::class)->lockdown($user, []);
      app(EmailChanges::class)->cancelPending($user);
  }
  app(PasswordSessions::class)->afterReset($user);
  ```

  Fortify では、アプリの `ResetUserPassword::reset()` の最初で `$wasUnverified` を読み、パスワードを書いた後に同じ所で消す（`EndSessionsAfterReset` はその後の `PasswordReset` で動く）

- **外部の連携は、確認済みのアドレスにだけ付ける。** 外部のログインのアドレスが同じでも、ローカルのアカウントが未確認なら、自動で結び付けない。外部の側でもアドレスが確認済み（Google の `email_verified` など）のときだけ照合に使う
