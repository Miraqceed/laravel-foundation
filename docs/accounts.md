# アカウントの乗っ取りの守り

名前空間 `Miraqceed\Foundation\Accounts`。パスワードかセッションを盗まれても、持ち主が締め出されないようにする。呼んだときだけ働く。経路・画面・メールは作らない。アプリの口（自前の作り・Fortify）から呼ぶ。

- パスワードの変更・再設定の後のセッション：`PasswordSessions`（この版）

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
