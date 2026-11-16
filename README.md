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
| 非負整数：整数の上限を超える和・積・切り上げ・比較 | 設定なしで呼んで使う | [docs/integer-math.md](docs/integer-math.md) |
| 実在しないメールアドレス：`example.com`・`.invalid` などの判定・入力の検証の規則・送る前に宛先から外す差し込み | 判定と規則は設定なしで使える。差し込みは無効（`foundation.mail.drop_reserved_recipients`） | [docs/reserved-email-domains.md](docs/reserved-email-domains.md) |
| ログインのロック：メールと接続元の失敗を数える（Fortify 対応） | 使うときに組み込む | [docs/login.md](docs/login.md) |
| CSP・セキュリティヘッダー | 無効（`foundation.security.enabled`） | [docs/security.md](docs/security.md) |
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

## 命名

表は `foundation_`、設定は `foundation.*`、コマンドは `foundation:*`。パッケージは秘密の値を持たず、設定（`.env`）から読むだけです。

## ライセンス

MIT
