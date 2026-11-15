# CSP・セキュリティヘッダー

名前空間 `Miraqceed\Foundation\Security`。**既定は無効**。

## いつ使うか

画面を返すアプリに、CSP（Content-Security-Policy）と防御のヘッダーを付けるとき。付くヘッダー：`Content-Security-Policy`（`report_only` なら `-Report-Only`）・`X-Content-Type-Options: nosniff`・`Referrer-Policy: strict-origin-when-cross-origin`・`Permissions-Policy`・`X-Frame-Options: DENY`・（`hsts` が真で https のとき）`Strict-Transport-Security`。応答がすでに決めている見出しは上書きしない。

## 最短の手順

1. `.env` ではなく `config/foundation.php`（`php artisan vendor:publish --tag=foundation-config`）で有効にする：

```php
'security' => ['enabled' => true],
```

2. **ミドルウェアは自分で `web` のグループに足す**（自動では足さない）。別名は `foundation.security-headers`（いつも登録される。無効のあいだは何もしない）：

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', 'foundation.security-headers');
})
```

3. インラインの `<script>`・`<style>` には nonce を付ける。Vite のタグは自動で同じ nonce になる：

```blade
<script nonce="{{ \Miraqceed\Foundation\Security\Nonce::get() }}">…</script>
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

決めたときだけ `POST /csp-report` を登録し、方針に `report-uri` を足す。報告は保存せず、`csp.violation` のログ（指示・止めた元・ページ。クエリとフラグメントは落とす）に書いて 204 を返す。1分に30回（接続元ごと）と 8KB までに絞る。

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
- ミドルウェアを `web` のグループへ足し忘れない（足さないと、有効にしても何も付かない）。
- 報告の口を `web` のグループに入れない。

## 限界

- `report_only` のときも、`X-Frame-Options` は付いたまま。`report_only`・例外の画面では、土台の指示（`object-src`・`base-uri`・`form-action`）の強制は残らない。
- CSP の `style-src-attr 'unsafe-inline'` を知らない古いブラウザ（Safari 15.4 未満・Firefox 108 未満）では `style` 属性が止まる。
