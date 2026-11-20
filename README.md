# laravel-foundation

どの Laravel のアプリにも要る部品を1つにまとめたパッケージです。Laravel 12〜13、PHP 8.3 以上、PostgreSQL 16 以上・MySQL 8.4・SQLite（試しのみ）に対応します。Redis や常駐のプロセスには頼らず、`database` のドライバーで動きます。

**入れただけではアプリの挙動は変わりません。ただしテストの DB の守りだけは、入れた時点で効きます。** テストがつなぐ DB の名前に `test` か `testing` が語として入っていないと（`app_test` は可、`latest` は不可）、テストは DB に触れる前に止まります。止まったときの直し方は [docs/test-safety.md](docs/test-safety.md) にあります。

設定で有効にする部品は既定で無効です。CSV・非負整数の計算・予約メールドメインの判定と入力の検証の規則は設定なしで呼び出せます。マクロ・ミドルウェアの別名・コマンドはいつも登録され、無効なら何もしません。ミドルウェアをグループへ自動で足すことはしません。

## 入れ方

```bash
composer require miraqceed/laravel-foundation
php artisan vendor:publish --tag=foundation-config   # config/foundation.php（任意）
php artisan foundation:install                       # AGENTS.md / CLAUDE.md に AI 向けの節を書く
```

入れた時点で、テストの DB の守りが効きます（上の注意）。`composer require` の後にテストが `UnsafeTestDatabase` で止まったら、テスト用 DB の名前に `test` か `testing` を入れます（[docs/test-safety.md](docs/test-safety.md)）。

`foundation:install` は `<!-- foundation:start -->` と `<!-- foundation:end -->` の間だけを書くので、何度流しても節は1つです。`AGENTS.md`・`CLAUDE.md` のうちあるものすべてに書き、どちらもなければ `AGENTS.md` を作ります。節の中身は Boost の指針と同じ原本から作ります。[Laravel Boost](https://laravel.com/docs/boost) を使うアプリでは、`boost:install` がこのパッケージの指針（`resources/boost/guidelines/core.blade.php`）を見つけ、「Which third-party AI guidelines/skills would you like to install?」の一覧に `miraqceed/laravel-foundation` を出します。**選んだときだけ入ります**（Boost の第三者の指針は選んで入れる形）。`--no-interaction` で流すときは、先に `boost.json` の `packages` に `"miraqceed/laravel-foundation"` を書いておきます。Boost の節と `foundation:install` の節は、同じファイルに並んでも互いを消しません。

## 部品

| 部品 | 既定 | 説明書 |
|---|---|---|
| テストの守り：名前に `test` / `testing` の印のない DB へのテストを、DB に触れる前に止める | 常に有効 | [docs/test-safety.md](docs/test-safety.md) |
| CSV：式の守り・Shift_JIS・ずれない大きな表の読み方 | 常に使える | [docs/csv.md](docs/csv.md) |
| ファイル名とファイルの中身：利用者の名前をダウンロードの名前・表示・列に収める（書記素で切る・使えない字・`Content-Disposition`）、先頭のバイトで種類を見分ける、写真の位置の情報を向きを残して消す。上げたファイルの流れの手本 | 設定なしで呼んで使う | [docs/files.md](docs/files.md)・[docs/uploads.md](docs/uploads.md) |
| 非負整数：整数の上限を超える和・積・切り上げ・比較 | 設定なしで呼んで使う | [docs/integer-math.md](docs/integer-math.md) |
| 実在しないメールアドレス：`example.com`・`.invalid` などの判定・入力の検証の規則・送る前に宛先から外す差し込み | 判定と規則は設定なしで使える。差し込みは無効（`foundation.mail.drop_reserved_recipients`） | [docs/reserved-email-domains.md](docs/reserved-email-domains.md) |
| メールアドレスの入力の整え方：全角・句点のアドレスを検証の前に半角にそろえるミドルウェア、保存する値と一意の列の比べの形（Gmail の別名）、同じ範囲のログインのロックの鍵、使い捨てのメールのドメインを断る規則 | 助けと鍵は設定なしで使える。ミドルウェアはアプリが入れる。使い捨ての規則は一覧のファイルをアプリが置く（`foundation.mail.disposable_domains`） | [docs/email-input.md](docs/email-input.md) |
| メールの中の安全：訪問者が書いた名前を文字のまま出す打ち消し・再設定と確認のリンクを要求の Host でなく `APP_URL` から作る設定・Host を偽った要求を入口で断る書き方・自動のメールの見出し（`Auto-Submitted`・送り直しても同じ `Message-ID`） | 打ち消しと見出しの助けは設定なしで使える。リンクとすべてのメールへの `Auto-Submitted` は無効（`foundation.mail.links_from_app_url`・`auto_submitted`） | [docs/mail-safety.md](docs/mail-safety.md) |
| 秘密を伏せる：文・入れ子の配列の中の鍵・トークン・パスワード・メールアドレス・電話番号を形で見つけて伏せる（漏れを無くすとは約束しない） | 設定なしで呼んで使う | [docs/redaction.md](docs/redaction.md) |
| ログインのロック：メールと接続元の失敗を数える（Fortify 対応） | 使うときに組み込む | [docs/login.md](docs/login.md) |
| 試みの数え：ログイン以外の照合（確認コード・合言葉・カードなど）を、照合の前に数えて止める。続けた失敗のロック | 設定なしで呼んで使う | [docs/attempts.md](docs/attempts.md) |
| 二段階の認証：認証アプリの確認コード（TOTP）を、使い回しと同時の要求を許さずに確かめる（手書きの認証と Fortify の差し替え） | 設定なしで呼んで使う。列は写したマイグレーションで足す | [docs/two-factor.md](docs/two-factor.md) |
| Turnstile：Cloudflare Turnstile のトークンをサーバーで確かめる（閉じる側に倒す・設定の誤りを記録して知らせる・規則とミドルウェア・本番のテスト用の鍵の点検） | 使ったときだけ働く。鍵は `config/services.php` | [docs/turnstile.md](docs/turnstile.md) |
| アカウントの乗っ取りの守り：パスワードの変更・再設定の後に、ほかの端末のセッションと remember の Cookie を効かなくし、この端末は入り直させる（自前の口と Fortify の聞き手）。メールアドレスの変更を新しいアドレスの確認で切り替え、古いアドレスへの戻すリンクで乗っ取った人を締め出す | セッションは設定なしで呼んで使う（Fortify の聞き手はアプリが登録する）。アドレスの変更は無効（`foundation.email_changes.enabled`。表を作る） | [docs/accounts.md](docs/accounts.md) |
| DB の接続の時間帯：接続の時間帯がアプリの時間帯とずれていないかを確かめる（`foundation:db-timezone`） | 設定なしで呼んで使う（自動では確かめない） | [docs/database-timezone.md](docs/database-timezone.md) |
| 本番の設定の点検：`APP_DEBUG`・`APP_KEY`・メール・Vite・マイグレーション・信頼するプロキシなどを1つのコマンドで調べる（`foundation:deploy-check`）。アプリの調べを足せる | 流したときだけ働く（自動では流れない） | [docs/deploy-check.md](docs/deploy-check.md) |
| 失敗の記録：ログが書けなくても投げない `report()`・DB に頼らない間引き・文の中の IP を伏せる助け・サーバーで作る要求の id（ログの行とジョブに渡す）・記録が壊れる場面を作る試しの助け | 記録は設定なしで呼んで使う。要求の id はアプリが中間を足したときだけ | [docs/failures.md](docs/failures.md) |
| 共用サーバーのキュー：毎分の cron から `database` のキューを回す worker の登録（重なり止め・`retry_after` との整合）と、キューの溜まり・新しく失敗したジョブ・DB の大きさの見張り | 登録はアプリが呼んだときだけ。見張りは無効（`foundation.monitoring.enabled`） | [docs/queue.md](docs/queue.md) |
| 子プロセスの環境変数：外のコマンドに `.env` の秘密を受け継がせない環境の配列 | 設定なしで呼んで使う | [docs/child-processes.md](docs/child-processes.md) |
| 1回だけ使える合言葉：期限つき・ハッシュだけで持ち、同時の要求でも1回だけ通す（機械にトークンを受け取らせる合言葉・入場券など） | 無効（`foundation.one_time_codes.enabled`。表を作る） | [docs/one-time-codes.md](docs/one-time-codes.md) |
| 知らせを1回だけ送る：条件に達したときの知らせを鍵ごとに1回だけ送る。送れなければ印を戻して送り直す。止まった印の取り直し・トランザクションの中・キューのジョブでも1回 | 無効（`foundation.notices.enabled`。表を作る） | [docs/once-notices.md](docs/once-notices.md) |
| 消す決まりの台帳：すべての表の消し方を1つのクラスに書き、表・列・schedule の書き忘れを試しで止める。退会・解約したアカウントを、握って確かめ直してから台帳のとおりに消す | 台帳（`foundation.retention.map`）を書いたときだけ | [docs/retention.md](docs/retention.md) |
| 要求の本文の上限と中間処理の並び順：経路ごとの本文の大きさの上限（回数の制限より前に断る）と、経路の中間処理が実際にどの順で動くかを確かめる試しの助け | 上限はアプリが経路か全体に掛けたときだけ | [docs/http.md](docs/http.md) |
| 日本語の入力の誤りの文：Laravel の規則・認証・再設定・ページ送りの日本語の文をアプリの `lang/ja` に写す（上書きしない）。足りない鍵と英語の混ざった誤りの文を見つける試しの助け | 写したときだけ効く（`vendor:publish --tag=foundation-lang-ja`） | [docs/validation-ja.md](docs/validation-ja.md) |
| 住所まわり（日本向け）：郵便番号から住所を引く（外のサービスに問い合わせない。一覧はアプリが `foundation:postal-codes` で日本郵便のデータから作る）・電話番号を市外局番の表で区切る・検証の規則 | 呼んだときだけ働く。郵便番号の一覧はアプリが作る（`foundation.japan.postal_codes.path`） | [docs/japan-address.md](docs/japan-address.md) |
| CSP・セキュリティヘッダー | 無効（`foundation.security.enabled`） | [docs/security.md](docs/security.md) |
| 稼働の確かめ：外のスクリプトを読まず、原因を出さない `/up`（text/plain の `OK`／`NG`）。DB の確かめを足せる | アプリが `bootstrap/app.php` で `Health::route('/up')` に書き換えたときだけ。DB は見ない（`foundation.health.checks`） | [docs/health.md](docs/health.md) |
| 定期の処理の見張りと知らせ | 無効（`foundation.monitoring.enabled`） | [docs/monitoring.md](docs/monitoring.md) |
| AI の費用の上限と記録（Laravel AI SDK で呼ぶ AI の1日・1か月の金額と1日の回数に上限、呼び出しごとの使用量と原価） | 無効（`foundation.ai.enabled`）。`laravel/ai` ^1.4 が要る | [docs/ai.md](docs/ai.md) |

利用者が入れた URL をサーバーから開くときの守り（SSRF）は、このパッケージに入れず、別のパッケージ `cboxdk/laravel-ssrf` を使います。使い方と注意は [docs/ssrf.md](docs/ssrf.md) にあります。

## 例：schedule の外の処理を見張る

```php
// config/foundation.php
'monitoring' => [
    'enabled' => true,
    'external' => ['backup' => ['max_age_minutes' => 1560, 'label' => '日次のバックアップ']],
    'alerts' => ['channels' => ['log']],
],
```

```bash
# 失敗も記録したうえで、台本の終了コードを残す（1行の && … || … だと終了コードがいつも 0 になる）
if ./backup.sh; then
    php artisan foundation:record backup
else
    status=$?
    php artisan foundation:record backup --failed
    exit "$status"
fi
```

知らせの表（`foundation_alerts`・`foundation_alert_deliveries`）は自動では消えません。送れたか諦めて `monitoring.alerts.prune_days`（既定 90）日たった知らせを消すには、`model:prune` を schedule に入れ、パッケージのモデルを `--model` で指します（`--model` なしの `model:prune` は `app/Models` しか見ません）：

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Monitoring\Alerts\Alert::class]])->daily();
```

キャッシュのストアが `database` なら、期限切れの行も自動では消えません（Laravel はその鍵がもう一度読まれたときにだけ消します）。`foundation:cache-prune` を schedule に入れます（[docs/monitoring.md](docs/monitoring.md)）：

```php
// routes/console.php
Schedule::command('foundation:cache-prune')->hourly();
```

## 命名

表は `foundation_`、設定は `foundation.*`、コマンドは `foundation:*`。パッケージは秘密の値を持たず、設定（`.env`）から読むだけです。

## ライセンス

MIT

### 出典

- 市外局番の表（`resources/japan/area-codes.json`）：総務省ホームページ「電気通信番号指定状況」（https://www.soumu.go.jp/main_sosiki/joho_tsusin/top/tel_number/number_shitei.html）を加工して作成（公共データ利用規約（第1.0版）。CC BY 4.0 互換）
- 郵便番号の一覧はパッケージに入れていない。アプリが日本郵便株式会社の郵便番号データから作る（`docs/japan-address.md`）
