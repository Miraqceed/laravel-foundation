## miraqceed/laravel-foundation

どのアプリにも要る部品（テストの守り・CSV・ログインのロック・CSP・見張りと知らせ）を1つにまとめたパッケージ。部品は `config/foundation.php` で有効にする（入れただけでは挙動は変わらない）。自前で書き直す前に、`vendor/miraqceed/laravel-foundation/docs/` の説明書を読む。

### テストの守り（`docs/test-safety.md`）

- 常に効く。テスト用 DB の名前に `test` か `testing` を**語として**入れる（`app_test`）。印のない DB につなごうとしたテストは DB に触れる前に止まる。
- 接続先を環境変数（`--env-file`・`-e DB_DATABASE=…`）で渡さない。`.env.testing` か `phpunit.xml` に書く。
- テストで読む外の DB は、名前を偽らずに `foundation.test_safety.ignore_connections` に接続名を挙げる。

### CSV（`docs/csv.md`）

- 書き出しは `CsvResponse::download()`。式の守り・BOM・Shift_JIS・途中で失敗した印は内蔵。自前の `fputcsv` や `'` の付与をしない。
- `lazy()`・`chunk()` で書き出さない（行が重複・欠落する）。`Rows::cursor()` はずれないがメモリーは減らない。大きな表は `Rows::keyset()`（一意の列を非修飾の名前で渡す）。

@verbatim
<code-snippet name="CSV を書き出す" lang="php">
use Miraqceed\Foundation\Csv\CsvResponse;
use Miraqceed\Foundation\Csv\Rows;

return CsvResponse::download('一覧.csv', ['ID', '会社名'], (function () {
    foreach (Rows::keyset(Company::query(), 'id') as $c) {
        yield [$c->id, $c->name];
    }
})());
</code-snippet>
@endverbatim

### ログインのロック（`docs/login.md`）

- 失敗の数えを自前で作らない。`LoginLock` を使う。Fortify では `fortify.limiters.login` を `null` にし、さらに `Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline())` を渡す（両方要る）。

### CSP・セキュリティヘッダー（`docs/security.md`）

- `foundation.security.enabled` を真にし、ミドルウェア `foundation.security-headers` を**自分で** `web` のグループに足す。
- `'unsafe-inline'` を足して済ませない。外の元は `foundation.security.areas` に足す。インラインの `<script>`・`<style>` には `Nonce::get()` の nonce を付ける。
- 違反の報告の口（`report_route`）を `web` のグループに入れない。

### 見張りと知らせ（`docs/monitoring.md`）

- `foundation.monitoring.enabled` を真にして migrate。schedule の処理には `->watched('名前', 許す古さ（分）)`。schedule の外の処理は終わりに `php artisan foundation:record 名前` を呼び、`foundation.monitoring.external` に書く。
- 知らせの送り先は `foundation.monitoring.alerts.channels`。MiraqStudio の顧客のアプリは `log` を使う。`foundation:install` で、この節を `AGENTS.md`・`CLAUDE.md` に写せる。
