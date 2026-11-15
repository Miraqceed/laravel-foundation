# 変更の記録

## 未公開

## 0.1.0

最初の版。Laravel 12〜13・PHP 8.3 以上。PostgreSQL 16 以上・MySQL 8.4・SQLite（試しのため）。Redis と常駐のプロセスに頼らない。

**入れただけではアプリの挙動は変わらない。ただしテストの DB の守りだけは、入れた時点で効く。** テストがつなぐ DB の名前に `test` か `testing` が語として要る（`app_test`）。止まったら `docs/test-safety.md` のとおりに直す。

- テストの DB の守り：印のない DB へつなごうとしたテストを、DB に触れる前に止める（`db.factory` を包む。並列の試しにも効く）。`docs/test-safety.md`
- CSV：`CsvResponse::download()`（式の守り・BOM・Shift_JIS・途中で失敗した印）、`CsvReader::parse()`（UTF-8・BOM 付き・Shift_JIS）、ずれない読み方 `Rows::cursor()`・`Rows::keyset()`。`docs/csv.md`
- ログインのロック：`LoginLock`（メールアドレスと接続元の組・接続元の2つの枠、IPv6 は /64）と Fortify への差し込み `AuthenticateWithLoginLock`。`docs/login.md`
- CSP・セキュリティヘッダー：ミドルウェア `foundation.security-headers`（既定は無効）、nonce、区分ごとの方針、違反の報告の口。`docs/security.md`
- 定期の処理の見張りと知らせ：`->watched()`・`foundation:record`・`foundation:watch`・`foundation:alerts:retry`、送り先は log・mail・webhook（HMAC の署名）・chatwork・slack の5つ（既定は無効）。知らせの表は `model:prune` で消す（設定 `monitoring.alerts.prune_days`、既定 90 日。**アプリが `model:prune --model=…Alert` を schedule に入れる**）。`docs/monitoring.md`
- AI 向けの指針（Laravel Boost の `resources/boost/guidelines/core.blade.php`）と、同じ原本から `AGENTS.md`・`CLAUDE.md` に節を書く `foundation:install`
