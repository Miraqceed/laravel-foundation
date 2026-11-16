# メールの中の安全と、要求の Host を信じないこと

名前空間 `Miraqceed\Foundation\Mail`。口は2つ：本文の助け `MailText::escape()`（設定なしで呼べる）と、再設定・確認のリンクを `APP_URL` から作る設定（既定で無効。`foundation.mail.links_from_app_url`）。あわせて、Host を偽った要求をアプリの入口で断る書き方（Laravel の `trustHosts`）を書く。

## いつ使うか

メールに入るものを、訪問者が操れないようにする。穴は2つ。

- **リンクの行き先**：Laravel の既定は、再設定のリンク（トークンつき）と確認のリンクを、要求の Host から作る。Host を偽った要求で再設定を頼むと、本人に届くメールのリンクが別のサイトに向き、押すとトークンが渡る
- **本文**：訪問者が書いた名前などは、メールの Markdown にそのまま入る。名前に `[ここを押す](https://evil.example)` と書けば、このサービスから出たメールの中のリンクになる

会員登録・パスワードの再設定・メールアドレスの確認のあるアプリには、どちらも要る。

## 本文：訪問者が書いた文を文字のまま出す

```php
use Miraqceed\Foundation\Mail\MailText;

return (new MailMessage)
    ->greeting(MailText::escape($user->name.' さん'))
    ->line(MailText::escape($comment));
```

- **つないだ全体を渡す。** 戻りは `HtmlString` で、文字列につなぐと `HtmlString` でなくなり、Blade が二重に打ち消す（`&amp;#45;` が見える）。`MailText::escape($user->name).' さん'` と書かない
- Markdown の Mailable のテンプレートでは `{{ MailText::escape($name) }}` と書く（`{!! !!}` に生の値を渡さない）
- HTML と Markdown の特別な字（`&` `<` `>` `"` `'` `\` `` ` `` `*` `_` `{` `}` `[` `]` `(` `)` `#` `+` `-` `.` `!` `|` `~` `:`）を数値の文字参照（`&#45;` など）にする。改行は空白にする（改行で段落・見出し・引用・参照の定義を作らせない）。頭の空白も文字参照にする（字下げでコードの塊にさせない）
- HTML の版では、CommonMark が文字参照を文字として扱うので、リンク・画像・見出し・箇条・強調にならない。text の版（`Markdown::renderText()`）は実体参照を元の字に戻すので、名前が書いたとおりに読める。`\` で打ち消すと、text の版に `山田 太郎\-営業部` のように `\` が残る
- Laravel の版（12.0 から）、`view:cache`、`Markdown::withSecuredEncoding()` の有無によらず同じに効く

### 限界：確認が済むまで名前を出さない

名前に書いた素の URL（`https://evil.example`）は、Markdown ではリンクにならないが、受け手のメールの道具がリンクにすることがある。これはどの打ち消しでも止められない。他人のメールアドレスで登録すれば、その人に、このサービスの名前でリンクを送れる。

**メールアドレスの確認が済むまで、利用者が書いた名前をメールに出さない。** 確認のメール（`VerifyEmail`）の文に名前を入れない。

### `Markdown::withSecuredEncoding()` と `view:cache`

Laravel 12.15 から、`Markdown::withSecuredEncoding()` を呼ぶと、メールの Markdown の中の `{{ }}` が Markdown の字も打ち消す。ただし Laravel 12.70・13.36 より前は、`php artisan view:cache` の後にこれが外れる（メールの画面が、既定の echo でコンパイルしたファイルを使い回すため）。本番で `view:cache`（`optimize`）を流すアプリでは効かない。

アプリの全体の守りとして `withSecuredEncoding()` を呼ぶのはよい（`MailText::escape()` と一緒に使っても二重に打ち消さない）。ただし訪問者が書いた文には、版によらない `MailText::escape()` を使う。

## リンクを `APP_URL` から作る

```php
// config/foundation.php
'mail' => ['links_from_app_url' => true],
```

真のとき、パッケージの起動で `ResetPassword::createUrlUsing()` と `VerifyEmail::createUrlUsing()` を登録する（`Mail\AppUrlLinks`）。

- 再設定のリンク：`APP_URL` の根で `password.reset` の URL（`token`・`email`）
- 確認のリンク：`APP_URL` の根で `verification.verify` の署名つきの URL（期限は `auth.verification.expire`、既定 60 分）。署名は絶対の URL で作るので、Fortify・Breeze の `signed` の経路がそのまま通る
- 経路の名前と引数は Laravel の既定と同じ。`APP_URL` の道（`https://example.jp/app`）も保つ
- 全体の `URL` の状態（`URL::forceRootUrl`・`forceScheme`）は変えない。同じ要求の中のほかの `url()`・`route()` は、要求の Host のまま
- `APP_URL` が空なら、Laravel の既定のまま（要求の Host から作る）

**入れただけでは変わらない。** 既定は `false` で、Laravel のまま要求の Host から作る。複数のドメインで動くアプリ（テナントごとのサブドメインなど）は、真にしない（リンクがすべて `APP_URL` に向く）。

### アプリが自分で書いていれば、アプリが勝つ

プロバイダーの起動は、パッケージ → アプリの順。アプリが `AppServiceProvider` で `ResetPassword::createUrlUsing()`・`VerifyEmail::createUrlUsing()` を書いていれば、アプリのものが使われる。

`ResetPassword::toMailUsing()`・`VerifyEmail::toMailUsing()` でメールを丸ごと作っているアプリは、そのコールバックがリンクを作るので、この設定は使われない（そのコールバックの中で `APP_URL` から作る）。

### 限界：前段が https を終えるとき

確認のリンクは `https://`（`APP_URL` の scheme）で署名する。前段のプロキシ・ロードバランサーが https を終えてアプリに http で渡す作りで、アプリが `X-Forwarded-Proto` を信じていないと、アプリから見た要求の URL が `http://` になり、署名が合わずに 403 になる（Laravel の既定は、要求の URL で作って要求の URL で確かめるので、この食い違いが起きない）。

`trustProxies` で `X-Forwarded-Proto` を信じる（次の節）。

### キュー・コンソール

キュー・コンソールでは要求がなく、`url()` はもともと `APP_URL` から作る。穴は、要求の中で同期で送るメール（再設定の頼みなど）。この設定は、どちらでも同じリンクを作る。

## Host を信じない

リンクを `APP_URL` から作っても、`$request->getHost()`・`url()` を読むコード（メール以外のリンク・リダイレクト・og:url など）は、まだ要求の Host を使う。偽の Host は、アプリの入口で断る。パッケージは中間を足さない。`bootstrap/app.php` で次のように書く。

```php
use Illuminate\Http\Request;

->withMiddleware(function (Middleware $middleware) {
    // APP_URL の Host（とそのサブドメイン）でない要求を 400 で断る。ほかの Host は at: で足す
    $middleware->trustHosts();

    // 前段から受け取るのは https かどうか（と港）だけ。X-Forwarded-Host は信じない
    $middleware->trustProxies(
        at: ['10.0.0.0/8'],
        headers: Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
    );
})
```

### 何が Host を偽らせるか

Laravel 12・13 で同じ（13.19・13.33・13.42 で確かめた）。

| 信頼する前段（`trustProxies`） | 偽の Host がアプリに届く道 |
|---|---|
| なし | `Host: evil.example`。Web サーバーが知らない Host の要求をアプリに渡すとき（既定の仮想ホスト・共用サーバー） |
| `'*'`（見出しは既定） | `Host` と、直に送った `X-Forwarded-Host: evil.example`（`'*'` は呼んできた相手を信頼するので、訪問者が直に送っても信じる） |
| 範囲（見出しは既定） | `Host` と、前段が訪問者の `X-Forwarded-Host` を消さずに渡すとき（nginx は既定で、要求の見出しをそのまま渡す）。範囲の外から直に送った `X-Forwarded-Host` は信じない |
| どれでも、`headers:` から `X-Forwarded-Host` を外す | `Host` だけ |
| どれでも、`trustHosts()` を足す | 断る（400） |

- **`trustHosts()` が入口で断る。** `Host` も `X-Forwarded-Host` も、`APP_URL` の Host でなければ 400 にする
- **`headers:` から `X-Forwarded-Host` を外す。** Host は `APP_URL` で決まっていて、前段から受け取る必要がない。Laravel の既定の見出しは `X-Forwarded-Host` を含む
- **前段のプロキシの IP が分からないとき**（共用サーバーなど）も、Host は IP なしで閉じる：`headers:` から `X-Forwarded-Host` を外し、`trustHosts()` を足す。IP の扱いは `docs/login.md`
- `trustHosts()` は、環境が `local` のときと、試しの中（`runningUnitTests()`）では何もしない。試しで確かめるときは、環境を `production` にする

## 配置の前の点検

`php artisan foundation:deploy-check`（`docs/deploy-check.md`）の2つの調べが見る。

- `trusted_hosts`：`TrustHosts` があれば `ok`。なければ、`'*'`・`'**'` で `X-Forwarded-Host`（か `Forwarded`）を信じる設定は `FAIL`、ほかは `later`
- `mail_links`：`foundation.mail.links_from_app_url` が偽なら `skip`。真なら、パッケージのリンクの作り方が効いていれば `ok`、アプリが自分で作っていれば `info`

## 自分で書くときの注意

- 訪問者が書いた文を、メールの Markdown に `{{ $name }}` でそのまま入れない。`withSecuredEncoding()` だけに頼らない（12.15 より前にはなく、12.70・13.36 より前は `view:cache` で外れる）
- 打ち消しを `\` で書かない（text の版に `\` が残る）
- 再設定・確認のリンクを `url()`・`route()` で要求の Host から作らない。全体に `URL::forceRootUrl()` を掛けない（手元で `APP_URL` と違う Host で開くと、リンク・資産・リダイレクトがすべて `APP_URL` に飛ぶ。複数のドメインのアプリも壊れる）
- 確認のリンクを道だけの署名（`absolute: false`）で作ると、Laravel の `signed` の中間（絶対の URL で確かめる）を通らない
