# CSP・セキュリティヘッダー

名前空間 `Miraqceed\Foundation\Security`。**既定は無効**。

## いつ使うか

画面を返すアプリに、CSP（Content-Security-Policy）と防御のヘッダーを付けるとき。付くヘッダー：`Content-Security-Policy`（`report_only` なら `-Report-Only`）・`X-Content-Type-Options: nosniff`・`Referrer-Policy: strict-origin-when-cross-origin`・`Permissions-Policy`・`X-Frame-Options`（下）・（`hsts` が真で https のとき）`Strict-Transport-Security`。応答がすでに決めている見出しは上書きしない。

**`X-Frame-Options` は、その要求の区分の CSP の `frame-ancestors` から決める**（強制・`report_only` のどちらでも同じ決まり。Report-Only の CSP では `frame-ancestors` が効かないので、移行の間も同じ埋め込みの決まりを効かせるため）：

| 区分の `frame-ancestors` | `X-Frame-Options` |
|---|---|
| `'none'`（空の並びも同じ）・書いていない | `DENY` |
| `'self'` だけ | `SAMEORIGIN` |
| ほかの元を含む（`'self' https://partner.example` など） | 付けない（`X-Frame-Options` では表せない。強制の CSP の `frame-ancestors` に任せる） |

同じオリジンの iframe（PDF のプレビューなど）を許すなら、その区分の `frame-ancestors` を `["'self'"]` にする。`X-Frame-Options` は自分で付けない（付けると、応答の値が優先されて区分の決まりとずれる）。

## 最短の手順

1. `.env` ではなく `config/foundation.php`（`php artisan vendor:publish --tag=foundation-config`）で有効にする：

```php
'security' => ['enabled' => true],
```

2. **ミドルウェアは自分で、グローバルの一番外（先頭）に足す**（自動では足さない。無効のあいだは何もしない）：

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Miraqceed\Foundation\Security\SecurityHeaders::class);
})
```

- **`web` のグループに足すと、ルートが決まる前に返る応答にヘッダーが付かない。** ルートに合わない URL の 404、メンテナンス中の 503、グローバルの中間処理が返す応答（大きすぎる POST の 413 など）。これらのエラーの画面も HTML なので、`frame-ancestors`・`X-Frame-Options` がないとほかのサイトの枠に入れられる。
- **グローバルではクラス名で書く。** 別名 `foundation.security-headers` はルートの中間処理でしか解かれないので、`prepend('foundation.security-headers')` は「Target class does not exist」で落ちる。別名は、特定のルートだけに付けたいときに使う。
- ルートのない応答の区分も、パス（`area_paths`）で決まる（`/api/…` に合わない 404 は `api` の方針になる）。
- 転送・JSON・ダウンロードを含む、すべての応答に付く（`web` のグループに入れていたときの、グループの中のルートと同じ）。
- **ルートごとに `withoutMiddleware()` で外すことはできない**（グローバルの中間処理はルートから外せない）。ほかのサイトに埋め込ませたいページがあるなら、外すのではなく、そのパスを別の区分にして `frame-ancestors` に許す元を書く：

```php
'security' => [
    'areas' => [
        // web の指示を写したうえで、frame-ancestors だけを変える
        'embed' => [/* … */ 'frame-ancestors' => ['https://partner.example']],
    ],
    'area_paths' => [
        'embed' => ['embed/*'],
        'admin' => ['admin', 'admin/*'],
        'api' => ['api/*'],
    ],
],
```

- **今 `web` のグループに入れているアプリは、グループから外してグローバルの先頭へ移す。** 移している途中で両方に残っても、見出しは1つで、画面の nonce と CSP の nonce も一致する（内側が付け、外側はすでにある見出しを上書きしない）。

3. インラインの `<script>`・`<style>` には nonce を付ける。Vite のタグは自動で同じ nonce になる：

```blade
<script nonce="{{ \Miraqceed\Foundation\Security\Nonce::get() }}">…</script>
```

Laravel 12・13 の **Vue・React の starter kit** は、`resources/views/app.blade.php` にインラインの `<script>`（ダークモードの切り替え）と `<style>`（背景色）を持つ。CSP を有効にすると、どちらも nonce がないと止まる。**両方に `nonce="{{ Vite::cspNonce() }}"` を付ける**（ミドルウェアが Vite に同じ nonce を渡すので、`Nonce::get()` と同じ値になる）：

```blade
{{-- resources/views/app.blade.php（starter kit） --}}
<script nonce="{{ Vite::cspNonce() }}">
    (function () { /* ダークモードの切り替え（starter kit のまま） */ })();
</script>

<style nonce="{{ Vite::cspNonce() }}">
    html { background-color: oklch(1 0 0); } /* starter kit のまま */
</style>
```

4. まず `report_only` を真にして、ブラウザのコンソールで違反が出ないかを見てから強制に切り替える。**CSP で止められた読み込みは、テストにも画面の例外にも出ない**（ブラウザのコンソールにしか出ない）。実際のブラウザで開いて確かめる。

## 外の元を足す（決済・書体・Turnstile など）

`'unsafe-inline'` を足して済ませない。必要な元だけを、区分の指示に足す。**リストはアプリの値で置き換わる**（既定とマージされない）ので、既定の値も書き写す：

```php
'security' => [
    'areas' => [
        'web' => [
            'script-src' => ["'self'", "'nonce'", 'https://js.pay.jp'],
            'frame-src' => ['https://js.pay.jp'],
            // 書いていない指示（default-src など）は既定のまま
        ],
    ],
],
```

- `'nonce'`（引用符つき）と書いた元だけが `'nonce-…'` に置き換わる。`script-src` に nonce があるとブラウザは `'unsafe-inline'` を無視する。
- 区分は3つ：`web`（既定）・`admin`（Livewire・Alpine 向けに `'unsafe-inline'`・`'unsafe-eval'` を許す。Filament を想定）・`api`（`default-src 'none'`）。どの区分かは `area_paths`（上から順に当て、どれにも当たらなければ `web`。既定は `admin`・`admin/*` と `api/*`）。
- Vite の開発サーバー（`npm run dev`）は自動で許す。開発中だけ `style-src` の nonce が外れて `'unsafe-inline'` になる。
- `APP_DEBUG` のときの例外の画面（HTML）には CSP を付けない（Laravel のそれはインラインで動く）。**開発で素通しに見えても本番では効く。**

## 違反の報告を受ける

```php
'security' => ['report_route' => 'csp-report'],
```

決めたときだけ `POST /csp-report` を登録し、方針に `report-uri` を足す。報告は保存せず、`csp.violation` のログ（指示・止めた元・ページ。クエリとフラグメントは落とす）に書いて 204 を返す。1分に30回（接続元ごと。IPv6 は `/64` に丸める。ログインのロックと同じ数え方）と 8KB までに絞る。

- **逆プロキシの後ろでは `bootstrap/app.php` の `$middleware->trustProxies(at: …)` が前提**（`docs/login.md`）。設定しないと全員が同じ接続元になり、誰か1人の報告で全員の報告が 429 になる。
- `route:cache` のときも、この経路で全体の `TrimStrings`・`ConvertEmptyStringsToNull` を飛ばす設定は効く（経路はキャッシュから読まれる）。

- **この口は `web` のグループに入れない**（セッション・CSRF がない。パッケージが経路を `web` の外に登録する。自分で同じパスの経路を足さない）。
- **限界：** Content-Type が JSON（`application/json`・`application/reports+json`）の報告は、Laravel 自身が本文を丸ごと解く（`Request::createFromBase` が、ミドルウェアより前）ので、パッケージは 8KB で止められない。8KB の上限が効くのは `application/csp-report` など JSON でない型だけ。**JSON の型の巨大な本文は、Web サーバーの本文上限と PHP の `post_max_size` で止める。** 例（Caddy）：

```
@cspreport path /csp-report
request_body @cspreport {
    max_size 16KB
}
```

nginx なら、その `location = /csp-report` に `client_max_body_size 16k;`。

## してはいけないこと

- `'unsafe-inline'`・`'unsafe-eval'` を `web` の `script-src` に足して済ませない（nonce が無効になる）。
- ミドルウェアをグローバルの先頭へ足し忘れない（足さないと、有効にしても何も付かない）。`web` のグループだけに足さない（404・503・413 に付かない）。
- 報告の口を `web` のグループに入れない。
- starter kit の `app.blade.php` のインラインの `<script>`・`<style>` に nonce を付け忘れない。

## 限界

- `report_only` のときも、`X-Frame-Options` は区分の `frame-ancestors` から付く（ほかの元を含む区分では付かないので、`report_only` の間はほかの元からの埋め込みを止めない）。`report_only`・例外の画面では、土台の指示（`object-src`・`base-uri`・`form-action`）の強制は残らない。
- CSP の `style-src-attr 'unsafe-inline'` を知らない古いブラウザ（Safari 15.4 未満・Firefox 108 未満）では `style` 属性が止まる。
