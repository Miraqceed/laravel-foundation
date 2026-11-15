# laravel-foundation

どの Laravel のアプリにも要る部品を1つにまとめたパッケージです。Laravel 12〜13、PHP 8.3 以上、PostgreSQL 16 以上・MySQL 8.4・SQLite（試しのみ）に対応します。Redis や常駐のプロセスには頼らず、`database` のドライバーで動きます。

**入れただけではアプリの挙動は変わりません。** 部品は既定で無効です（テストの守りと CSV は設定なしで使えます）。マクロ・ミドルウェアの別名・コマンドはいつも登録され、無効なら何もしません。ミドルウェアをグループへ自動で足すことはしません。

## 入れ方

```bash
composer require miraqceed/laravel-foundation
php artisan vendor:publish --tag=foundation-config   # config/foundation.php（任意）
php artisan foundation:install                       # AGENTS.md / CLAUDE.md に AI 向けの節を書く
```

`foundation:install` は `<!-- foundation:start -->` と `<!-- foundation:end -->` の間だけを書くので、何度流しても節は1つです。`AGENTS.md`・`CLAUDE.md` のうちあるものすべてに書き、どちらもなければ `AGENTS.md` を作ります。[Laravel Boost](https://laravel.com/docs/boost) を使うアプリには、`boost:install` がこのパッケージの指針（`resources/boost/guidelines/core.blade.php`）を自動で読みます。

## 部品

| 部品 | 既定 | 説明書 |
|---|---|---|
| テストの守り：名前に `test` / `testing` の印のない DB へのテストを、DB に触れる前に止める | 常に有効 | [docs/test-safety.md](docs/test-safety.md) |
| CSV：式の守り・Shift_JIS・ずれない大きな表の読み方 | 常に使える | [docs/csv.md](docs/csv.md) |
| ログインのロック：メールと接続元の失敗を数える（Fortify 対応） | 使うときに組み込む | [docs/login.md](docs/login.md) |
| CSP・セキュリティヘッダー | 無効（`foundation.security.enabled`） | [docs/security.md](docs/security.md) |
| 定期の処理の見張りと知らせ | 無効（`foundation.monitoring.enabled`） | [docs/monitoring.md](docs/monitoring.md) |

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
./backup.sh && php artisan foundation:record backup || php artisan foundation:record backup --failed
```

## 命名

表は `foundation_`、設定は `foundation.*`、コマンドは `foundation:*`。パッケージは秘密の値を持たず、設定（`.env`）から読むだけです。

## ライセンス

MIT
