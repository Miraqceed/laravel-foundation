## miraqceed/laravel-foundation

どのアプリにも要る部品（テストの守り・CSV・ログインのロック・CSP・見張りと知らせ・AI の費用の上限）を1つにまとめたパッケージ。部品は `config/foundation.php` で有効にする（入れただけでは挙動は変わらない）。**ただしテストの DB の守りだけは入れた時点で効く**（テストの DB の名前に `test` か `testing` が語として要る。止まったら `docs/test-safety.md`）。自前で書き直す前に、`vendor/miraqceed/laravel-foundation/docs/` の説明書（`test-safety.md`・`csv.md`・`login.md`・`security.md`・`monitoring.md`・`ai.md`）を読む。

### テストの守り（`docs/test-safety.md`）

- 入れた時点で効く（有効にする設定はない）。テスト用 DB の名前に `test` か `testing` を**語として**入れる（`app_test`）。印のない DB につなごうとしたテストは DB に触れる前に止まる。
- 接続先を環境変数（`--env-file`・`-e DB_DATABASE=…`）で渡さない。`.env.testing` か `phpunit.xml` に書く。
- テストで読む外の DB は、名前を偽らずに `foundation.test_safety.ignore_connections` に接続名を挙げる。

### CSV（`docs/csv.md`）

- 書き出しは `CsvResponse::download()`。式の守り・BOM・Shift_JIS・途中で失敗した印は内蔵。自前の `fputcsv` や `'` の付与をしない。
- `lazy()`・`chunk()` で書き出さない（行が重複・欠落する）。`Rows::cursor()` はずれないがメモリーは減らない。大きな表は `Rows::keyset()`（一意の列を非修飾の名前で渡す）。
- 読み込みは `CsvReader::parse()`（UTF-8・BOM 付き・Shift_JIS を判定する。外れるときは `SourceEncoding` を渡す）。

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

### 非負整数の計算（`docs/integer-math.md`）

- PHP の整数の上限を超える非負整数の計算には `Miraqceed\Foundation\Support\UnsignedInteger` の `add`・`multiply`・`ceilDivideByPowerOfTen`・`compare` を使う。和・積・切り上げの結果は数字列、比較は `-1`・`0`・`1`。float に変換してから渡さない。
- 値は実際の非負 int または ASCII の数字列、`scale` は実際の非負 int。入力の桁数の制限と、結果を DB・JSON へ保存できる範囲はアプリで決める。

### ログインのロック（`docs/login.md`）

- 失敗の数えを自前で作らない。`LoginLock` を使う。Fortify では次の3つが**すべて**要る（1つでも欠けると回数の制限が消えるか二重になる）：①`config/fortify.php` の `limiters` の `login` を `null`、②`Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline())`、③`Fortify::authenticateUsing(...)` に `AuthenticateWithLoginLock` を渡す。
- **逆プロキシ（ロードバランサー・Caddy・nginx・Cloudflare）の後ろでは、`bootstrap/app.php` の `$middleware->trustProxies(at: …)` が必須。** 設定しないと全員が同じ IP（プロキシの IP）になり、接続元の枠がサイト全体のロックになる。

@verbatim
<code-snippet name="Fortify に組み込む（FortifyServiceProvider の boot）" lang="php">
use Laravel\Fortify\Fortify;
use Miraqceed\Foundation\Login\Fortify\AuthenticateWithLoginLock;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\NormalizedEmailKey;

// config/fortify.php には 'limiters' => ['login' => null] も書く
Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline());
Fortify::authenticateUsing(new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey)));
</code-snippet>
@endverbatim

### CSP・セキュリティヘッダー（`docs/security.md`）

- `foundation.security.enabled` を真にし、ミドルウェア `foundation.security-headers` を**自分で** `web` のグループに足す。
- `'unsafe-inline'` を足して済ませない。外の元は `foundation.security.areas` に足す。インラインの `<script>`・`<style>` には `Nonce::get()` の nonce を付ける。
- Laravel 12・13 の Vue・React の starter kit は、`resources/views/app.blade.php` にインラインの `<script>`（ダークモード）と `<style>` を持つ。どちらにも `nonce="@{{ Vite::cspNonce() }}"` を付ける（付けないと CSP で止まる）。
- `X-Frame-Options` は区分の `frame-ancestors` から決まる（`'none'` → DENY、`'self'` だけ → SAMEORIGIN、ほかの元を含む → 付けない）。iframe を許すときは `X-Frame-Options` を自分で付けず、`frame-ancestors` を直す。
- 違反の報告の口（`report_route`）を `web` のグループに入れない。報告の口の回数の制限（接続元ごと）も、逆プロキシの後ろでは `trustProxies` が前提。

### 見張りと知らせ（`docs/monitoring.md`）

- `foundation.monitoring.enabled` を真にして `php artisan migrate`。schedule の処理には `->watched('名前', 許す古さ（分）)`。`->environments()` で絞った処理はその環境の外では見張らないが、`->when()`・`->skip()` で止まる処理は見張る（条件で止まる処理に `->watched()` を付けない）。schedule の外の処理は終わりに `php artisan foundation:record 名前` を呼び、`foundation.monitoring.external` に書く。
- 知らせの送り先は `foundation.monitoring.alerts.channels`（用意した5つから選ぶ）。コンテナの標準エラーを集めて運ぶ環境では `log` を使い、`monitoring.alerts.log.channel` を `stderr` にする。`monitoring.alerts.log.level`（既定 `error`）がチャネルの `LOG_LEVEL` より低いと、ログは捨てられるのに送れた扱いになる。
- `foundation:watch` の知らせの鍵は止まっている処理の名前の組ごと（`foundation.watch:` ＋ 名前を並べ替えて `,` でつないだものの sha1）。新しく止まった処理はすぐ知らせ、同じ組は間引く。間引きは `throttle_minutes` が10より大きいとき `throttle_minutes - 5` 分（既定で55分）。鍵を `foundation.watch` の1つと思って読まない。
- 知らせの表は自動では消えない。`model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\Monitoring\Alerts\Alert` を指す（`--model` なしでは `app/Models` しか見ない）。送れたか諦めて `monitoring.alerts.prune_days`（既定 90）日たった知らせが消える。
- `foundation:install` で、この節を `AGENTS.md`・`CLAUDE.md` に写せる。

### AI の費用の上限と記録（`docs/ai.md`）

- AI は **Laravel AI SDK（`laravel/ai` ^1.4）で呼ぶ。各社の SDK や HTTP で直に呼ばない**（直に呼んだ分は数えられず、上限もかからない）。
- `foundation.ai.enabled` を真にして `php artisan migrate`。上限は `foundation.ai.daily_limit_yen`・`monthly_limit_yen`・`daily_call_limit`（空は上限なし、`0` はすべて止める）。金額の上限を使うなら `foundation.ai.prices` に単価（ドル／100万トークン）を書く。単価のないモデルは0円にせず、いちばん高い単価で数える。
- 上限に達すると `Miraqceed\Foundation\Ai\AiLimitReached` が投げられる。画面で捕まえて「今は AI を使えません」と出す。この例外は会社の切り替えを起こさない。キューのジョブでは `tries` の回数だけやり直す。
- 使った額を数えられないとき（単価の表が空・DB が読めないなど）は `foundation.ai.when_uncountable`（既定 `block`＝止める）に従う。
- 使用量の行は自動で消えない。`model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\Ai\AiUsage` を指す（`foundation.ai.prune_days`、既定 90）。
- 知らせは `foundation.monitoring.enabled` が真のときだけ届く（日・月に1回）。画像・音声・埋め込みなどは数えず止めない。
