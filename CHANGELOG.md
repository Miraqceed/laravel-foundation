# 変更の記録

## 未公開

## 0.2.0

**入れただけでは変わらない。** 新しい部品は既定で無効（`foundation.ai.enabled`）。使うには `laravel/ai` ^1.4 が要る（`composer.json` の `suggest`。`<1.4 || >=2.0` は `conflict`）。

- AI の費用の上限と記録：Laravel AI SDK で呼ぶ AI に、アプリ全体の1日・1か月の金額と1日の回数の上限をかけ、上限以上なら送らずに `AiLimitReached` を投げる。呼び出しごとの使用量と原価を `foundation_ai_usages` に、日ごとの合計を `foundation_ai_totals` に残す。単価の表（`foundation.ai.prices`）に既定値はなく、単価のないモデルは0円にせず、いちばん高い単価で数える。使った額を数えられないときは `foundation.ai.when_uncountable`（既定 `block`）に従う。上限に達したことは、見張りが有効なら運営へ知らせる（日・月に1回）。文の生成と分類だけを数え、画像・音声・埋め込みなどは数えず止めない。`docs/ai.md`
- 記録と合計は、アプリのトランザクションが巻き戻っても残るよう、別の接続 `foundation_ai`（アプリが定めていればそれ、なければ既定の接続の写し）の書き込みの側で読み書きする。
- マイグレーションは `foundation.ai.enabled` のときだけ読まれる。無効でも `vendor:publish --tag=foundation-ai-migrations` で写せる。使用量の行は `model:prune --model=Miraqceed\Foundation\Ai\AiUsage` で消す（`foundation.ai.prune_days`、既定 90）。
- AI 向けの指針に節を足した（`foundation:install` にも写る）。

## 0.1.2

- コードのコメント・説明書・AI 向けの指針から、特定のサービスの名前と事故の番号を外し、一般的な書き方にした。振る舞いは 0.1.1 と同じ。

## 0.1.1

- composer.json のキーワードから `testing` を外した。Packagist が試し用のパッケージとみなし、入れ方を `composer require --dev` と案内していた。`--dev` で入れると本番に入らず、CSV・ログインのロック・CSP・見張りが動かない。入れ方は `composer require miraqceed/laravel-foundation`（`--dev` を付けない）。コードは 0.1.0 と同じ。

## 0.1.0

最初の版。Laravel 12〜13・PHP 8.3 以上。PostgreSQL 16 以上・MySQL 8.4・SQLite（試しのため）。Redis と常駐のプロセスに頼らない。

**入れただけではアプリの挙動は変わらない。ただしテストの DB の守りだけは、入れた時点で効く。** テストがつなぐ DB の名前に `test` か `testing` が語として要る（`app_test`）。止まったら `docs/test-safety.md` のとおりに直す。

- テストの DB の守り：印のない DB へつなごうとしたテストを、DB に触れる前に止める（`db.factory` を包む。並列の試しにも効く）。`docs/test-safety.md`
- CSV：`CsvResponse::download()`（式の守り・BOM・Shift_JIS・途中で失敗した印）、`CsvReader::parse()`（UTF-8・BOM 付き・Shift_JIS）、ずれない読み方 `Rows::cursor()`・`Rows::keyset()`。`docs/csv.md`
- ログインのロック：`LoginLock`（メールアドレスと接続元の組・接続元の2つの枠、IPv6 は /64）と Fortify への差し込み `AuthenticateWithLoginLock`。`docs/login.md`
- CSP・セキュリティヘッダー：ミドルウェア `foundation.security-headers`（既定は無効）、nonce、区分ごとの方針、違反の報告の口。`docs/security.md`
- 定期の処理の見張りと知らせ：`->watched()`・`foundation:record`・`foundation:watch`・`foundation:alerts:retry`、送り先は log・mail・webhook（HMAC の署名）・chatwork・slack の5つ（既定は無効）。ログの1行と Webhook の本文（v1）は知らせの `id` を持つ（受け取る側が送り直しの重複を除く）。知らせの表は `model:prune` で消す（設定 `monitoring.alerts.prune_days`、既定 90 日。**アプリが `model:prune --model=…Alert` を schedule に入れる**）。`docs/monitoring.md`
- AI 向けの指針（Laravel Boost の `resources/boost/guidelines/core.blade.php`）と、同じ原本から `AGENTS.md`・`CLAUDE.md` に節を書く `foundation:install`
