# 失敗を本筋に持ち込まない記録

名前空間 `Miraqceed\Foundation\Failures`・`Miraqceed\Foundation\RequestId`。**入れただけでは変わらない。** 記録の道具は呼んだときだけ、要求の id の中間はアプリが足したときだけ働く。

- `FailureReporter::report()`：投げない `report()`。DB に頼らない間引きつき
- `FailureReporter::redactIp()`：文の中の IP を、書き方の揺れも含めて伏せる助け
- 中間 `foundation.request-id`：要求の id をサーバーで作り、ログの行とキューのジョブに渡す
- `Testing\ReportFailure::simulate()`：`report()` が投げる場面を作る試しの助け

## いつ使うか

失敗を記録してから続けたい所（`catch` の中）。転送・バッチ・定期の処理・決済の後始末・知らせの送り直しなど。

Laravel の `report()` は投げることがある。ログのファイルを開けないと Monolog が例外を投げ、ロガーを作れないと Laravel は**元の例外を投げ直す**。`catch` の中で投げると、記録したかった失敗が、本筋ごと止める失敗に化ける。

```php
use Miraqceed\Foundation\Failures\FailureReporter;

try {
    $this->forward($request);
} catch (Throwable $e) {
    app(FailureReporter::class)->report($e);   // 投げない
}
```

- どの道でも投げない。記録に失敗したら、PHP の `error_log()` に1行だけ残す（`foundation: report() failed for <元の例外のクラス> (<記録の失敗のクラス>)`）。ログのチャネルを通らないので、Monolog が壊れていても残る。**例外の文は書かない**（IP などが入りうるため）
- 例外は書き換えない。元のまま `report()` に渡すので、アプリの `dontReport`・`reportable`・`level()`・`context()` はそのまま効く
- 容器に1つ。注入でも `app()` でも使える

## 間引き

同じ失敗が続く所（訪問のたびに走る道で、DB が落ちたときなど）は、鍵を渡す。その鍵ごとに、窓（秒、既定 60）の中で1回だけ記録する。プロセスをまたいで効く。

```php
app(FailureReporter::class)->report($e, 'checkout.store:'.$e::class, 60);
```

- **目印は storage のファイルに置く**（既定 `storage/framework/foundation/report-throttle`。`foundation.failures.throttle_path` で変えられる）。キャッシュ・DB は使わない。間引きたいのは DB が落ちているときで、キャッシュが `database` だと、間引きも失敗して何も記録されず、記録のたびに DB への接続を余分に試みる。Laravel の `$exceptions->throttle()` は、キャッシュ（既定のストア）で数える
- 目印を読めない・書けないときは記録する（間引きの失敗で記録を隠さない）
- **鍵は失敗の種類にする**（所とクラスの名前など）。利用者ごと・要求ごとの値（IP・メールアドレス・id）を入れない。鍵の数だけ目印のファイルが残る。ファイルの名前は鍵の SHA-256 で、鍵の中身は出ない
- サーバーが複数なら、サーバーごとに間引く（記録は台数倍になる）
- 試しでは、`foundation.failures.throttle_path` を試しごとの一時のディレクトリにする（storage に残った目印で、後の試しの記録が間引かれないように）

## 例外の文に入る値

**このパッケージは、記録する例外の文を伏せない。** 伏せるのはアプリ。失敗の文には、次の値がそのまま入りうる。

1. **SQL にバインドした値**：`QueryException` の文は、既定で SQL に値を埋めて書く。Laravel 13.27 から、接続の設定 `mask_bindings_in_exception_messages` を真にすると `?` のまま残す。`foundation:deploy-check` の `db_masked_bindings` が見る

   ```php
   // config/database.php の接続ごとに
   'mask_bindings_in_exception_messages' => true,
   ```

2. **DB の誤りの文そのもの**：上の設定を真にしても残る。一意の違反の文は、ぶつかった値を書く

   | DB | 文（抜き出し） |
   |---|---|
   | MySQL | `1062 Duplicate entry '203.0.113.5' for key 'clicks.ip'` |
   | PostgreSQL | `DETAIL:  Key (ip)=(203.0.113.5) already exists.` |

   メールアドレスの一意の列なら、メールアドレスが同じように残る。**IP やメールアドレスを入れる列の失敗は、伏せた文で例外を作り直してから記録する**（下）

3. **積み跡の引数**：PHP の `zend.exception_ignore_args` が Off だと、ログの積み跡に各段の文字列の引数（15字まで。IPv4 はそのまま収まる）が入る。`php.ini` を読まない PHP と `php.ini-development` では Off。Web と CLI の両方の `php.ini` で On にする。`foundation:deploy-check` の `exception_args` が CLI の値を見る

### IP を伏せる `redactIp()`

```php
try {
    DB::table('clicks')->insert(['ip' => $ip, /* ... */]);
} catch (Throwable $e) {
    app(FailureReporter::class)->report(new RuntimeException(sprintf(
        'クリックを記録できない: %s: %s', $e::class, FailureReporter::redactIp($e->getMessage(), $ip),
    )), 'clicks.insert:'.$e::class);
}
```

- 文の中の、渡した IP の書き方のすべてを `[ip]` にする：渡した書き方・正規の形・IPv6 の大文字と小文字・`::` の省略・0 の省略・全部の形（`2001:0db8:0000:…`）。IPv4 は `::ffff:203.0.113.5`・`::ffff:cb00:7105` の形も。IPv4 を埋め込んだ IPv6（`::ffff:0:0/96`・`64:ff9b::/96`）を渡せば、中の IPv4 も
- 長い数・長いアドレスの一部では当てない（`203.0.113.5` を伏せても `203.0.113.50`・`1203.0.113.5` は残る）。文の終わりの `.` の前では当てる
- IP として読めない値は、書いたとおり（大文字と小文字は問わない）だけを伏せる。`null`・空なら文をそのまま返す
- 渡していない IP は探さない（版の番号 `1.2.3.4` などを誤って伏せないため）。逆プロキシの後ろでは、`$request->ip()` と、保存に使った値（`REMOTE_ADDR` など）が違うことがある。保存に使った値を渡す
- 壊れた UTF-8 の文でも投げない
- メールアドレス・トークンは、秘密を伏せる部品で伏せる（`docs/redaction.md`）：`app(Redactor::class)->text(mb_scrub($e->getMessage(), 'UTF-8'))->value`

## 要求の id

中間 `foundation.request-id` を、`bootstrap/app.php` で全体の先頭に足す。

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\Miraqceed\Foundation\RequestId\AssignRequestId::class);
})
```

- 要求の id はサーバーで作る（ULID）。**利用者が送った `X-Request-Id` は読まない**（ログを偽らせない）
- 上流の `X-Correlation-Id` は、`A-Z a-z 0-9 . _ : -` の128字以内のときだけ引き継ぐ（改行・制御の字でログの行を偽らせない）。なければ要求の id と同じ。上流を信頼するかは見ない（偽られても、ログの行の結びが乱れるだけ）
- Laravel の `Context` に `request_id`・`correlation_id` で入れる。**ログの行**（`extra`）と、**要求の中で入れたキューのジョブ**に、Laravel が自動で渡す
- 応答の見出し `X-Request-Id`・`X-Correlation-Id` に付ける（404・500 の誤りの応答にも）
- コンソール・予定の処理では作らない（要るなら `Context::add('request_id', …)` を自分で呼ぶ）

### 外への HTTP と JSON の誤り

外への HTTP には付けない（決済・メール・AI などの外の事業者に、こちらの id を送る理由はない）。自分のサービスへ送るときだけ付ける：

```php
use Miraqceed\Foundation\RequestId\RequestId;

Http::withHeaders(RequestId::headers())->post($internalUrl, $payload);
```

JSON の誤りの本文に入れるなら、アプリの `bootstrap/app.php` で：

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
        if ($response instanceof JsonResponse) {
            $response->setData([...(array) $response->getData(true), 'request_id' => RequestId::current()]);
        }

        return $response;
    });
})
```

`RequestId::current()`・`correlation()` は、中間を通っていなければ `null`。ジョブの中では、ジョブと一緒に運ばれた値を返す。

## 試し：記録が壊れても本筋は止まらない

```php
use Miraqceed\Foundation\Testing\ReportFailure;

public function test_ログが書けなくても転送は止まらない(): void
{
    $attempts = ReportFailure::simulate();

    $this->get('/r/abc')->assertRedirect('https://example.com/');

    $this->assertCount(1, $attempts);   // 記録しようとした例外の文
}
```

- 本物の例外の扱い（ハンドラー）に、いつも投げる `reportable` を足す。投げる例外は、Monolog がファイルを開けないときと同じ形。ハンドラーは試しごとに作り直されるので、戻す手間は要らない
- **`Exceptions::fake()` の中では使えない**（`report()` が投げないので場面を作れない。`LogicException` で止める）。試しの全体に `Exceptions::fake()` を掛けているなら、その試しだけ外す
- `FailureReporter` は `error_log()` に1行書く。PHPUnit 11 以降は、試しの間の `error_log()` を拾って出力に出す。出したくなければ、試しの中で `ini_set('error_log', …)` を一時のファイルに向ける
- 本番のコードからは呼ばない

## パッケージの中の記録

このパッケージの中で失敗を記録して続ける所（稼働の確かめ・定期の処理の記録・知らせ・AI の上限と記録・CSV の書き出し・`foundation.attempts.bypass` の知らせ）は、`FailureReporter` を使う。ログが書けなくても、見張りの知らせ・`/up` の `NG`・CSV の終わりの印は止まらない。
