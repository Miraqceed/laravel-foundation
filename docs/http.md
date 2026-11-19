# 要求の本文の上限と、中間処理の並び順

- 中間処理 `Http\LimitBodySize`（別名 `foundation.body-limit`）：要求の本文の大きさで断る
- 試しの助け `Testing\RouteMiddleware`：経路の中間処理が、実際にどの順で動くかを確かめる

**入れただけでは変わらない。** 中間処理は、アプリが経路か全体に掛けたときだけ働く。

## 本文の上限 `foundation.body-limit`

Laravel の全体の上限は、PHP の `post_max_size`（`ValidatePostSize`）だけで、経路ごとの上限はない。`post_max_size` はアップロードのために大きくしてあることが多く、API は大きな本文を数え・検証してから断ることになる。

```php
// 経路ごとに（引数は上限のバイト数）
Route::post('/api/v1/jobs', StoreJobController::class)->middleware('foundation.body-limit:65536');
```

- 名乗った大きさ（`Content-Length`）が上限を超えれば、本文を読まずに断る
- 名乗らない本文・小さく名乗った本文は、実際の長さで確かめる（上限＋1バイトまでしか数えない）。`Request` の状態には触れないので、上限を通った後のコントローラーは `getContent()`・`json()`・`input()` をふつうに読める
- `multipart/form-data` は、名乗った大きさで、名乗らなければ受け取ったファイルの大きさとフォームの欄で測る（アップロードの1つのファイルの大きさは、検証の `max:` で確かめる）
- 断ると、`Log::warning('foundation.body_limit', ['path', 'bytes', 'limit'])` を残し、413 の `HttpException` を投げる。形はアプリの例外の扱いが決める（JSON を求める要求には JSON）。記録に本文・クエリ文字列は入らない。大きな本文を送り続けられると、その数だけ行が増える
- 引数の書き損じ（数字でない・0）は、本番でない環境では `InvalidArgumentException`、本番では断らずに通して記録する
- **回数の制限より前に動く**：パッケージが、Laravel の優先の表で `ThrottleRequests` の前に入れる（`throttle` を先に書いても、本文の上限が前に動く）。アプリが `bootstrap/app.php` の `$middleware->priority([...])` で表を丸ごと書き換えても残る。書き換えた表に `ThrottleRequests` がなければ、表の最後に入る

### 全体に掛ける

`post_max_size` より小さいサイト全体の上限を、経路の中間処理より前に効かせる。全体ではクラス名で書く（別名は経路でしか解かれない）。部品4のセキュリティヘッダーと要求の id より内側に置く（413 の応答にも見出しと id が付く）。

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend([
        \Miraqceed\Foundation\RequestId\AssignRequestId::class,
        \Miraqceed\Foundation\Security\SecurityHeaders::class,
        \Miraqceed\Foundation\Http\LimitBodySize::class.':10485760',
    ]);
})
```

### JSON の本文は、どの中間処理でも解く前には断れない

**Laravel は、JSON の要求の本文を、要求を作る時点（`Request::createFromBase()`）で丸ごと解く。** 全体の中間処理を含め、どの中間処理より前。そのため、この中間処理の役目は、上限を超えた本文を**回数の制限・検証・コントローラーより前に断ること**で、「解く前に断ること」ではない。

大きな本文を PHP に解かせないのは、次の2つだけ。

- **Web サーバーの上限**：nginx の `client_max_body_size`、Apache の `LimitRequestBody` など。経路ごとに分けられるなら、同じ上限にそろえる
- PHP の `post_max_size`

## 中間処理の並び順

経路の中間処理（グループと経路に書いたもの）は、Laravel の優先の表（`middlewarePriority`）で並べ替えられる。

- 表にある中間処理は、先に出た表の中間処理より表で前なら、**その前へ割り込む**
- **表にない中間処理は動かない**。書いた位置のまま
- 全体の中間処理は並べ替えない（いつも経路の中間処理より外で、書いた順に動く）

そのため、次の罠を踏む。

- **自前の認証を表に入れずに `throttle` の後に書くと、後のまま動く。** 回数の制限は `$request->user()` が null のまま数え、全利用者が1つの数えを分け合う（混む時間に全員が待たされる）
- **運営の入口（Basic 認証・接続元の制限など）が `throttle` より後だと、401 に `X-RateLimit-*` が付き、管理の画面があることを外に教える**
- 入口で 404 にする中間処理が、Cookie・セッション・CSRF・`throttle` より後だと、419・429 で経路があることが分かる

書き方：

- **自前の認証は、Laravel の認証の印 `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` を実装する。** 表の `AuthenticatesRequests` の位置（`throttle` より前）に並ぶ。表に名前で入れない（認証を差し替えると外れる）
- 運営の入口・入口の 404 は、`bootstrap/app.php` で表に入れる：

```php
$middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\ThrottleRequests::class, prepend: AdminGate::class);
$middleware->prependToPriorityList(before: \Illuminate\Cookie\Middleware\EncryptCookies::class, prepend: HideUnknownPages::class);
```

### 試しで並びを固定する `RouteMiddleware`

```php
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Miraqceed\Foundation\Http\LimitBodySize;
use Miraqceed\Foundation\Testing\RouteMiddleware;

public function test_認証と本文の上限は回数の制限より前(): void
{
    RouteMiddleware::assertBefore('GET', '/api/v1/jobs/next', AuthenticatesRequests::class, ThrottleRequests::class);
    RouteMiddleware::assertBefore('POST', '/api/v1/jobs', LimitBodySize::class, ThrottleRequests::class);
    RouteMiddleware::assertMissing('POST', '/api/v1/link', AuthenticatesRequests::class);
}
```

- `for($method, $uri)`：その要求が当たる経路の、実際に動く順の中間処理（クラス名と `:引数`）。当たる経路がなければ `NotFoundHttpException`
- `assertBefore($method, $uri, $first, $second)`・`assertHas(...)`・`assertMissing(...)`
- 比べる相手は、クラス・interface・親のクラスで当てる（`AuthenticatesRequests` を渡せば、印を持つ自前の認証も当たる）
- HTTP の Kernel を作ってから読む（Kernel が中間処理の別名と優先の表をルーターに写すので、まだ要求を送っていない試しでも、実際と同じ並びになる）
- 全体の中間処理は含めない
