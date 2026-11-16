# 稼働の確かめ（/up）

名前空間 `Miraqceed\Foundation\Health`。口は2つ：コントローラー `HealthCheck`（text/plain の `OK`／`NG` を返す）と、経路を1行で登録する `Health::route()`。設定 `foundation.health.checks` で、確かめに DB を足せる（既定は見ない）。

**入れただけでは変わらない。** パッケージは経路を登録しない。アプリが `bootstrap/app.php` を書き換えたときだけ働く。

## いつ使うか

Laravel の既定の稼働の確かめ（`withRouting(health: '/up')`）を置き換えるとき。既定の `/up` には次の2つがある：

- **外のスクリプトを読む HTML を返す。** 12.0.0 は版のない `https://cdn.tailwindcss.com`、その後の 12.x と 13 は `https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4`（メジャーだけ固定）を、SRI なしで読む。自分のオリジンで他者のスクリプトが動き、脆弱性の診断で「Cross-Domain JavaScript Source File Inclusion」として指摘される。CSP を全体に掛けたアプリでは、そのスクリプトが止まって見た目が崩れる
- **デバッグ中（`APP_DEBUG=true`）は例外を投げ直す。** エラーの画面に原因（DB の接続先など）が出る

`/up` を見るのは人でなく、デプロイの確かめ・外の死活監視・ロードバランサーで、どれも状態の番号だけを見る。この口は HTML を返さない。

## 置き換え方

`bootstrap/app.php` で `health:` を消し、`then:` に書く：

```php
use Miraqceed\Foundation\Health\Health;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // health: '/up' は使わない（外のスクリプトを読む HTML を返すため）
        then: fn () => Health::route('/up'),
    )
```

`Health::route($path = '/up')` は次を登録する（Laravel の `health:` と同じことを、この口でする）：

- `GET`（`HEAD` も）`$path` → `HealthCheck`。経路の名前は `foundation.health`。戻りは登録した経路
- メンテナンス中も応答する（`PreventRequestsDuringMaintenance::except($path)`）

`then:` の中でグループを付けずに登録するので、`web` のグループ（セッション・CSRF）を通らない。`routes/web.php` に書かない（確かめのたびにセッションができる）。全体のミドルウェア（CSP など）は通る。クロージャでなくクラスで登録するので、`route:cache` の後も動く。

## 返す形

| 状態 | 番号 | 本文 |
|---|---|---|
| どの確かめも通った | 200 | `OK` |
| どれかが例外を投げた | 500 | `NG` |

- いつも `Content-Type: text/plain; charset=UTF-8`・`Cache-Control: no-store`（間の CDN・プロキシに `OK` を覚えさせない）
- JSON は返さない。JSON を求める要求にも同じ文字を返す
- 原因は `report()` で記録にだけ残し、応答に出さない。**デバッグ中も同じ**（投げ直さない）
- メンテナンス中（`php artisan down`）も 200 の `OK`。デプロイの `down` の間に外の監視が鳴らない

## 確かめるもの

Laravel の既定と同じく、`Illuminate\Foundation\Events\DiagnosingHealth` を出し、聞き手が投げたら `NG` にする。既定では、聞き手はない（何も見ない。アプリが生きていて要求に答えられれば `OK`）。

### DB（`foundation.health.checks`）

```php
// config/foundation.php
'health' => ['checks' => ['database']],
```

`'database'` を書くと、既定の接続で `select 1` を流し、つなげなければ `NG` にする。

- DB の一時の不調でも `NG` になり、デプロイの確かめや外の監視が鳴る。`/up` を「生きているか」だけに使う監視があるなら、書かない
- DB の接続の待ちが長いと、確かめも長くなる。監視の待ちの長さより、接続の待ち（PDO の `ATTR_TIMEOUT` など）を短くする
- 聞き手として足すので、Laravel の既定の `/up` を使い続けるアプリでも、書けば既定の `/up` も DB を見る
- **知らない名前（書き損じ。例 `'databse'`）は、起動を止めない。** `/up` の確かめの中で `InvalidArgumentException` になり、`report()` して `NG` を返す。ほかの経路は動く。デプロイの確かめや外の監視が `/up` の `NG` で気付ける

### ほかのもの

キャッシュ・キュー・外の API などは、アプリが `DiagnosingHealth` の聞き手を足す（Laravel の仕組みのまま）。投げたら `NG`：

```php
Event::listen(DiagnosingHealth::class, function () {
    Cache::get('health-check');
});
```

## 重ねて登録したとき

`withRouting()` は、`health:` の経路を `then:` より先に登録する。同じメソッドと道の経路を後から足すと、前のものが置き換わる。

| 書き方 | 起きること |
|---|---|
| `health: '/up'` を消し忘れ、`Health::route('/up')` | 後の `Health::route()` が置き換え、`/up` はこの口の `OK`。害はない |
| `health: '/up'` を消し忘れ、`Health::route('/healthz')` | `/up` は Laravel の既定（外のスクリプトを読む HTML）のまま残る |
| `Health::route('/up')` を2回 | 後のものが置き換え、経路は1つ。害はない |
| `Health::route('/up')` と `Health::route('/healthz')` | 2つの経路が同じ名前 `foundation.health` を持つ。`route:cache` が `LogicException` で落ちる |

害のある2行目と4行目は、**本番でない環境（試しの中を含む）では、`Health::route()` が `LogicException` で止める**。文に直し方が出る。経路はアプリの Feature の試しのたびに読まれるので、消し忘れは試しで見つかる。

**本番（`APP_ENV=production`）では止めない**（`report()` もしない）。経路をキャッシュしないアプリでは `then:` が要求のたびに動くので、止めるとサイトの全部の要求が 500 になるため。本番では4行目は `route:cache` が止める。2行目は本番では誰も止めないので、手元か試しで見つける。

Laravel 12 の既定の経路は、`ApplicationBuilder` の中で作ったクロージャなので、クロージャを作ったファイルの名前で見分ける。Laravel がそのファイルを動かすと見分けられなくなり、止めないだけになる。

## 限界

- アプリが全体のミドルウェアに `StartSession` を入れていると、`then:` で登録してもセッションができる
- 確かめの内訳（どれが落ちたか）は応答に出さない。記録（`report()`）で見る
- 認証付きの詳しい状態の画面は持たない
