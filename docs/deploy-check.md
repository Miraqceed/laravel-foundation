# 本番の設定の点検

コマンド `foundation:deploy-check` と、名前空間 `Miraqceed\Foundation\DeployCheck`。本番の設定を1か所で調べ、違う所の id と説明を出して終わりの値 1 で終わる。流したときだけ働く（schedule に入らない。書き込まない）。

## いつ使うか

配置の台本の最後（`migrate` と `up` の後）と、CI で流す。新しいアプリが最初の公開の前に踏む穴（`APP_DEBUG`・長さの違う `APP_KEY`・届かないメールの設定・`view:cache` で外れるメールの守り・残った `public/hot` など）を、まとめて止める。**点検を自前で書き直さず、足りない調べは `Check` で足す。**

```bash
php artisan foundation:deploy-check            # 1行ずつ。失敗があれば終わりの値 1
php artisan foundation:deploy-check --json     # 機械向け
php artisan foundation:deploy-check --strict   # later（まだ要らない）も失敗とみなす（公開の直前）
```

```
[ok]    app_debug                APP_DEBUG が偽（訪問者に例外の中身を見せない）
[FAIL]  mail                     メールが届く設定（…）: MAIL_FROM_ADDRESS が実在しないドメイン（example.com など）
[FAIL]  migrations               流していないマイグレーションがない: QueryException
[skip]  boost                    Laravel Boost が切れている（…）: 入っていない
[later] payment_keys             決済の本番の鍵: 販売を始めるまで
[info]  trusted_proxies          信頼するプロキシ（…）: 前段の IP・範囲: 2 件
設定の点検: 失敗 2（docs/deploy-check.md）
```

- 行の形は `[状態] id 説明: 詳しく`。状態は `ok`・`FAIL`・`later`・`skip`・`info`
- **id は変えない約束。** 台本で行を読むときは id で読む（`grep -qE '^\[ok\] +migrations '`）。機械で読むなら `--json`
- 終わりの値：`FAIL` が1つでもあれば 1、なければ 0。`--strict` では `later` も 1
- `--json`：`{"schema_version": 1, "passed": bool, "checks": [{"id", "status", "label", "detail"}]}`。`status` は `ok`・`fail`・`later`・`skip`・`info`

## 既定の調べ

| id | 満たすこと | 当たらないとき |
|---|---|---|
| `app_env` | `APP_ENV` が `production` | |
| `app_debug` | `APP_DEBUG` が偽 | |
| `app_url` | `APP_URL` が https の URL | |
| `app_key` | `APP_KEY`（と `APP_PREVIOUS_KEYS`）が暗号の方式（`app.cipher`）に合う長さ。入っているだけでは足りない（長さが違うと、暗号を使う所で初めて落ちる） | |
| `boost` | Laravel Boost が切れている（`BOOST_ENABLED=false`）。Boost は `APP_DEBUG` が真だと、どの環境でも口を開く | 入っていない（`composer install --no-dev`） |
| `session_secure_cookie` | `SESSION_SECURE_COOKIE` が真 | |
| `drivers` | キャッシュ・セッションが `array` でない（種類の名前を出す） | |
| `mail` | 送り方が `log`・`array` でない。SMTP のホストが空・`127.0.0.1`・`localhost` でない。`MAIL_FROM_ADDRESS` がメールアドレスで、実在しないドメイン（`example.com`・`.test` など。`docs/reserved-email-domains.md`）でない | |
| `mail_domain` | 送信元のドメインに SPF・DMARC がある。DKIM はセレクターを書いたときだけ（下。**DNS を引く**） | `mail` が失敗する設定 |
| `mail_view_cache` | 通知のメールの画面が `view:cache` でコンパイルされていない（下） | Laravel 12.15 より前・直った版（12.70・13.36 から）か、`Markdown::withSecuredEncoding()` を使っていない |
| `vite_hot` | `public/hot`（`npm run dev` の印）がない | |
| `vite_manifest` | `public/build/manifest.json` があり、JSON として読める | `vite.config.*` がない |
| `writable` | `storage/logs`・`storage/framework/{cache,sessions,views}`・`bootstrap/cache` に書ける | |
| `migrations` | 流していないマイグレーションがない（既定の接続へつなぐ。読むだけ） | |
| `attempts_bypass` | `foundation.attempts.bypass` が偽（`production` では効かないが、知らせが1時間に1回出続ける） | |
| `trusted_proxies` | 下 | |
| `trusted_hosts` | 偽の Host を入口で断る（`bootstrap/app.php` の `trustHosts()`。下） | |
| `mail_links` | 再設定・確認のメールのリンクを `APP_URL` から作る設定が効いている（下） | `foundation.mail.links_from_app_url` が偽 |
| `password_sessions` | パスワードの変更・再設定の後に、ほかの端末のセッションを終えられる（下） | |
| `turnstile` | Turnstile の鍵（`services.turnstile`）が両方入っていて、Cloudflare のテスト用でない（下） | 鍵がどちらも空 |
| `db_masked_bindings` | 既定の接続（と `foundation.deploy_check.connections`）の `mask_bindings_in_exception_messages` が真（下） | Laravel 13.27 より前（`info`） |
| `exception_args` | コマンド（CLI）の `zend.exception_ignore_args` が On（下） | |
| `queue_worker` | キューのジョブを回す worker がある（下） | キューの driver が `database`・`sync` でない |
| `queue_timeouts` | `CronWorker` の worker の timeout が `retry_after` より10秒以上短く、timeout が効く（下） | `CronWorker` の worker がない |
| `retention_schedule` | 消す決まりの台帳の、期限で消すモデルとコマンドが schedule にある（下） | `foundation.retention.map` を書いていない |
| `postal_codes` | 郵便番号の一覧が、日本郵便の更新日から3か月以内（下） | 一覧を置いていない（`source.json` がない） |

当たらない調べは `skip`。アプリに当たらない既定の調べは、`config/foundation.php` で外す：

```php
'deploy_check' => ['skip' => ['session_secure_cookie']],   // 例：画面のない API だけのアプリ
```

### `mail_view_cache`

`php artisan optimize` を `--except=views` なしで流すと、`view:cache` がメールの画面を素の echo でコンパイルする。直っていない版の Laravel（12.15〜12.69・13.0〜13.35）では、Markdown の描画がそのファイルを使い回し、`Markdown::withSecuredEncoding()`（訪問者が書いた文の `[` `]` `<` を、メールの中でリンクにしない）が、そのメールから外れる。配置の台本では `php artisan optimize --except=views` にし、外れていたら `php artisan view:clear`。

直った版（12.70.0・13.36.0 から）は、echo の形ごとに別のファイルへコンパイルするので外れない。調べは版の番号でなく振る舞い（echo の形を変えると、コンパイル済みのファイルの道が変わるか）で見分け、直った版では `skip` にする。

### `trusted_proxies`

信頼するプロキシ（`bootstrap/app.php` の `$middleware->trustProxies(at:, headers:)`、なければ `config('trustedproxy.proxies')`）を読む。値（アドレス）は出さない。

- 既定は `info` だけ（逆プロキシの後ろかどうかは、パッケージには分からない）
- **逆プロキシの後ろで、訪問者の本当の IP が要るアプリ**（ログインのロック・CSP の違反の報告の口・`IpBucket` で数える `Attempts` を使う）は `required` にする。前段の IP・範囲を挙げていなければ失敗：

```php
'deploy_check' => ['proxies' => 'required'],
```

| 設定 | 既定 | `required` |
|---|---|---|
| 未設定 | `info` | `FAIL`（全員がプロキシの IP になる） |
| `'*'`・`'**'`、`X-Forwarded-For`・`Forwarded` を信頼（既定の見出し） | `info` | `FAIL`（Laravel 13.20 から、訪問者が IP を選べる。13.19 までも、上げた時点でそうなる） |
| `'*'`、IP を運ぶ見出しを信頼しない（`headers: Request::HEADER_X_FORWARDED_PROTO`） | `info` | `FAIL`（全員がプロキシの IP になる） |
| 前段の IP・範囲を挙げている | `info`（件数） | `ok` |
| アプリの `TrustProxies` の子が全体のミドルウェアにある（古い作り） | `info`（読めない） | `FAIL`（確かめられない） |

`'*'` の版の違いと、前段のアドレスの書き方は `docs/login.md`。

### `trusted_hosts`

全体のミドルウェアに `TrustHosts`（`$middleware->trustHosts()`、またはアプリの `TrustHosts` の子）があるかを読む。なければ、信頼するプロキシと見出しを `trusted_proxies` と同じ読み方で読む。値（Host・アドレス）は出さない。

| 設定 | 状態 |
|---|---|
| `TrustHosts` がある | `ok` |
| `TrustHosts` がなく、`'*'`・`'**'` で Host を運ぶ見出し（`X-Forwarded-Host`・`Forwarded`）を信頼（既定の見出し） | `FAIL`（訪問者が直に送った `X-Forwarded-Host` がリンクになる。Web サーバーの設定では閉じない） |
| `TrustHosts` がなく、ほかの設定（未設定・範囲・`headers:` から Host を外した `'*'`） | `later`（Web サーバーが知らない Host を断っていれば穴はない。パッケージからは見えない） |
| `TrustHosts` がなく、アプリの `TrustProxies` の子が全体のミドルウェアにある | `later`（見出しは読めない） |

書き方は `docs/mail-safety.md`。

### `mail_links`

`foundation.mail.links_from_app_url` が真のとき、`ResetPassword`・`VerifyEmail` のリンクの作り方（`createUrlUsing`）が、パッケージのものかを見る。URL は出さない。

| 設定 | 状態 |
|---|---|
| 偽 | `skip` |
| 真で、パッケージの作り方が効いている | `ok` |
| 真で、アプリが自分の `createUrlUsing`・`toMailUsing` で作っている | `info`（アプリが勝つ。どちらの通知かだけを出す） |
| 真なのに、作り方が登録されていない | `FAIL` |

### `turnstile`

`services.turnstile.site_key`・`secret_key`（`docs/turnstile.md`）を見る。値は出さない。

| 設定 | 状態 |
|---|---|
| どちらも空 | `skip`（Turnstile を使っていない） |
| 一方だけが空 | `FAIL`（秘密がなければ確かめがすべて断る・サイトの鍵がなければウィジェットが出ない） |
| どちらかがテスト用（`1x`・`2x`・`3x` の後に0が並ぶ） | `FAIL`（本番で、誰でも通すか、誰も通さない） |
| 両方入っていて、テスト用でない | `ok` |

### `password_sessions`

パスワードの後のセッションの後始末（`Accounts\PasswordSessions`。`docs/accounts.md`）が、ほかの端末を締め出せる形かを見る。部品を使っていないアプリにも当たるので、失敗にはしない。当たらないアプリは `foundation.deploy_check.skip` に `password_sessions` を足す。

| 設定 | 状態 |
|---|---|
| `SESSION_DRIVER=database` | `ok`（行を消せる） |
| web のグループに `auth.session`（`AuthenticateSession`・その子。`$middleware->authenticateSessions()`） | `ok`（次の要求でログアウトする） |
| どちらもない | `info`（パスワードを変えても、ほかの端末のセッションが残る） |

### `db_masked_bindings`

失敗した SQL の例外（`QueryException`）の文に、バインドした値（訪問者の IP・メールアドレスなど）を入れない設定（Laravel 13.27 から）を見る。接続の名前だけを出す。

```php
// config/database.php の接続ごとに
'mask_bindings_in_exception_messages' => true,
```

| 設定 | 状態 |
|---|---|
| 見た接続がすべて真 | `ok` |
| 真でない接続がある | `later`（その接続の名前を出す） |
| Laravel 13.27 より前 | `info`（その版には設定がない） |

**真でも、DB の誤りの文そのものには値が残る**（一意の違反の `Duplicate entry '…'`・`DETAIL: Key (…)=(…)`）。どの状態でも、その旨を `detail` に添える。扱い方は `docs/failures.md`。

### `exception_args`

PHP の `zend.exception_ignore_args` が Off だと、ログの積み跡に、各段の文字列の引数（15字まで。IPv4 はそのまま収まる）が入る。`php.ini` を読まない PHP と `php.ini-development` では Off。

| 設定 | 状態 |
|---|---|
| On | `ok` |
| Off | `later` |

見えるのはコマンド（CLI）の値だけ。共用サーバーなどで Web が別の `php.ini` を読むなら、そちらも On にする。

### `queue_worker`

キューの既定の接続で、ジョブが回るかを見る（`docs/queue.md`）。接続先は出さない。

| 設定 | 状態 |
|---|---|
| driver が `sync` | `later`（`->delay()` が無視され、ジョブが要求の中で動く） |
| `database` で、`CronWorker::schedule()` の worker か、コマンドが `queue:work` の schedule の処理がある | `ok` |
| `database` で、どちらもない | `FAIL`（ジョブが溜まるだけ） |
| ほかの driver（`redis` など） | `skip`（常駐の worker が別にある前提） |

### `queue_timeouts`

`CronWorker::schedule()` で登録した worker ごとに、ジョブが二重に取られないかを見る。

| 設定 | 状態 |
|---|---|
| `timeout + 10 > retry_after`（10 秒は余白） | `FAIL`（処理中のジョブを次の worker が取り直す。どの worker かを出す） |
| CLI に pcntl がない | `later`（`--timeout` が効かない） |
| すべて満たす | `ok` |
| `CronWorker` でない `queue:work` だけ | `info`（オプションを読まないので確かめない） |
| worker がない | `skip` |

### `retention_schedule`

消す決まりの台帳（`foundation.retention.map`。`docs/retention.md`）の期限の消し方が、本番の schedule にあるかを見る。試しの `DataMapAssertions::assertScheduled()` と同じことを、本番の設定と環境で確かめる。DB の表は見ない（本番の DB の作りを読まない）。

| 設定 | 状態 |
|---|---|
| `foundation.retention.map` を書いていない | `skip` |
| 台帳を読めない（クラスが `DataMap` でない・書き違い） | `FAIL` |
| `prunedBy` のモデルの `model:prune`・`prunedByCommand` のコマンドが、今の環境で動く schedule にない | `FAIL`（足りないものと、足す1行を出す） |
| すべてある | `ok` |

パッケージの表（`foundation_notices` など）は、部品を有効にしているときだけ数える。

### `postal_codes`

郵便番号の一覧（`foundation:postal-codes` が `foundation.japan.postal_codes.path` に作る。`docs/japan-address.md`）の `source.json` の更新日（日本郵便の2つの元のうち古い方）を見る。日本郵便は毎月更新する。

| 一覧 | 状態 |
|---|---|
| `source.json` がない | `skip`（郵便番号の部品を使っていない） |
| `source.json` を読めない（JSON でない・更新日がない・日付でない） | `FAIL`（作り直す） |
| 更新日から3か月の日（日本時間）の終わりまで | `ok`（更新日を出す） |
| それより古い | `later`（古くても引けるので止めない。公開の直前の `--strict` で失敗。`php artisan foundation:postal-codes` で作り直して PR にする） |

### `mail_domain`

送信元（`MAIL_FROM_ADDRESS` の `@` より後）のドメインの DNS の TXT を引き、受け手が確かめる記録があるかを見る。ドメインの名前は出す（秘密でない）。アドレスのローカル部は出さない。

| 設定・DNS | 状態 |
|---|---|
| `mail` が失敗する設定（`log`・`array`・例示の送信元など） | `skip`（DNS を引かない） |
| ドメインの TXT に `v=spf1`、`_dmarc.<ドメイン>` に `v=DMARC1` がある（セレクターがあれば `<セレクター>._domainkey.<ドメイン>` に `p=` も） | `ok` |
| どれかがない | `later`（公開の直前の `--strict` で失敗。迷惑メールに入る・受け取られない） |
| DNS を引けない（`dns_get_record()` がない・失敗した） | `info` |

DKIM を見るときは、メール事業者のセレクターを書く：

```php
// config/foundation.php
'deploy_check' => [
    'dkim_selector' => 'mail1',
],
```

**DNS の待ち。** 引くのは最大3回（SPF・DMARC・DKIM）。`dns_get_record()` は待つ時間を決められず、DNS が応えない環境では、1回ごとに OS の resolver の待ち（`/etc/resolv.conf` の `timeout`×`attempts`×名前のサーバーの数。数十秒になりうる）だけ止まる。1回でも引けなければ、残りを引かずに `info` で終える（止まるのは1回分）。遅い・外へ DNS を引けない環境（共用サーバー・外への通信を止めた CI）では外す：

```php
'deploy_check' => [
    'skip' => ['mail_domain'],
],
```

## アプリの調べを足す

```php
namespace App\DeployChecks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

final class PaymentKeys implements Check
{
    public function id(): string
    {
        return 'payment_keys';     // 英小文字・数字・_。一度出したら変えない
    }

    public function label(): string
    {
        return '決済の本番の鍵';    // 満たすべきこと。値を入れない
    }

    public function run(): Result
    {
        $secret = (string) config('services.payment.secret');

        // 値が入っていて形が違えば、公開の前でも失敗（テスト用の鍵を本番に入れた）
        if ($secret !== '' && ! str_starts_with($secret, 'sk_live_')) {
            return Result::fail('本番の鍵でない');
        }
        if ($secret === '') {
            // 公開の前はまだ要らない。公開のフラグを上げたら必須
            return config('billing.open') ? Result::fail('入っていない') : Result::later('販売を始めるまで');
        }

        return Result::ok();
    }
}
```

```php
// config/foundation.php
'deploy_check' => [
    'checks' => [\App\DeployChecks\PaymentKeys::class],   // 容器から作る。既定の調べの後に、この並びで走る
],
```

- 結果は `Result::ok()`・`fail()`・`later()`・`skip()`・`info()`。いつ必須になるかは調べが決める（公開のフラグを読む）
- 公開の前に「上げたら通るか」を見るときは `--strict`
- 調べは**読むだけ**にする（DB・キャッシュ・ファイルに書かない。ログを書かない）

## 秘密を出さない

- `label()` と `detail` に設定の値（鍵・パスワード・接続先・URL）を入れない。「何が違うか」を値なしで書く（「本番の鍵でない」「SMTP のホストが手元」）
- 調べが例外を投げたら、その行を失敗にし、**例外のクラス名だけ**を出す（文には DB のユーザー名・ホストが入る）。残りの調べは続く。作るときの例外・見つからないクラス・`Check` でないクラス・重なる id・形の違う id も、その行だけ失敗（`invalid_check`）

## 限界

- このコマンドを流した利用者と PHP（CLI）で見る。Web のサーバーの PHP（拡張・`php.ini`）や利用者（書き込み）が違えば、そちらは見ない
- `migrations` は DB へつなぐ。つながらないと、接続の待ち（ドライバーの時間切れ）の分だけ遅くなる
- `mail_view_cache` が見るのは通知のメールの画面（`notifications::email`）だけ
- `vite_manifest` は manifest の場所を変えたアプリ（`Vite::useBuildDirectory()`・`useManifestFilename()`）を見ない
- PHP の版・拡張、DB の種類・版、決済・外の API の鍵などは、アプリの調べで足す
