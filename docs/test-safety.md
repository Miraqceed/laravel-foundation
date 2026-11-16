# テストの守り

## いつ使うか

**いつも効く（有効にする設定はない）。** テストが開発用の DB（名前に `test` / `testing` の語がない DB）につなごうとしたら、DB に触れる前に `UnsafeTestDatabase` を投げて止める。
元の事故：コンテナの環境変数（`docker run --env-file`・`-e DB_DATABASE=…`・compose の `environment`）は `$_SERVER` に入り、Laravel はそれを先に読むので、`phpunit.xml` の `<env … force="true"/>` より勝つ。
開発用 DB に向けたままテストを流すと、`RefreshDatabase` が中身をすべて消す。

## 最短の手順

1. テスト用 DB の名前に、**語として** `test` か `testing` を入れる（`app_test`・`testing`・`app-test-1`。`latest`・`contest` は語でないので不可）。区切りは `_` `.` `-`。
2. 使うアプリの CI の DB 名にも印を付ける。
3. 並列の試し（`php artisan test --parallel`）でも、プロセスごとの DB 名（`app_test_test_1`）に印が残るので、そのまま通る。
   **元のテスト用 DB（`app_test`）は先に作っておく。** Laravel はプロセスごとの DB を作るために元の DB へつなぐので、ないとすべての試しが `Unknown database 'app_test'`（PostgreSQL では `database "app_test" does not exist`）で落ちる。新しい開発の環境や CI で、元の DB を作る手順を忘れない。
4. 印の語を変えるなら `config/foundation.php`：

```php
'test_safety' => ['markers' => ['test', 'testing', 'spec']],
```

テストで読み取りだけに使う外の DB（印のない名前）は、**名前を偽らずに**接続名で外す：

```php
'test_safety' => ['ignore_connections' => ['legacy_readonly']],
```

## 外への通信を止める（任意。既定は無効）

`Http::fake()` を書き忘れた試しは、本物の外の API（決済・メール・AI など）を呼ぶ。開発者の `.env` に本物の鍵があれば、試しの中で本当に請求や送信が起きる。
次の設定を真にすると、**テストの中だけ** `Http::preventStrayRequests()` を掛け、`Http::fake()` に当たらない要求を送る前に `StrayRequestException` で止める：

```php
'test_safety' => ['prevent_stray_requests' => true],
```

- 新しいアプリでは真にする。既定が偽なのは、今ある試しのうち本物の通信をするもの（外の試し用の API へつなぐ結合の試しなど）を、版を上げただけで落とさないため。
- テストの中かは、DB の守りと同じ判定（PHPUnit の `TestCase` が読み込まれているか・並列の印）。本番や `artisan` では掛けない。
- 本物の通信が要る試しは、その試しの中で `Http::allowStrayRequests()` を呼んで外す（Laravel の新しい版では URL の一覧を渡せる）。
- **限界：** 止まるのは Laravel の HTTP クライアント（`Http::`）を通る要求だけ。SDK が自分の HTTP クライアントで送る通信は止まらない。試しでは、その SDK の鍵を `phpunit.xml` の `<env name="…" value="" force="true"/>` で空に固定する。

## してはいけないこと

- 接続先を環境変数で渡さない（`--env-file`・`-e DB_*`・`DB_URL`）。止まったら、作業フォルダの `.env.testing` か `phpunit.xml` に書いて流す。
- 印を付けるために開発用 DB の名前を変えるのは構わないが、**印のない DB に `test` を名前だけ付け足して外から見せかけない**。外の DB は `ignore_connections` に挙げる。
- ガードを外すために `runningTests()` の判定を偽らない。

## 限界

- 確かめるのは接続を作る前（`db.factory` を包む `GuardedConnectionFactory::make`）。**`db.factory` を包まずに置き換えるパッケージが後から入ると、主な口が外れる**（控えとして接続ができたときの `ConnectionEstablished` でも確かめるが、`Event::fake()` の後は届かない）。`db` が `register` の段階で早く作られると、包む前の factory のままのことがある。
- `ignore_connections` の `x` は、`x::read`・`x::write` を含むが、**`x::direct` は外れない**（止まる側に倒れる）。
- sqlite のメモリ DB `:memory:` は通す。**`file::memory:?cache=shared` は止まる**（ファイル名として読まれ、印がない）。sqlite のファイルはファイル名（拡張子を除く）に印が要る。
- テストの中かどうかは PHPUnit の `TestCase` が読み込まれているか（と paratest の親プロセスへ渡る印）で見る。`APP_ENV` では決めない。
- Pest・collision の `artisan test` は、知らない引数の後ろに置いた自分の引数を読まない。守りは引数に関わらず効くが、引数を足して流す手順を書くときは確かめる。
