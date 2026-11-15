# テストの守り

## いつ使うか

**いつも効く（有効にする設定はない）。** テストが開発用の DB（名前に `test` / `testing` の語がない DB）につなごうとしたら、DB に触れる前に `UnsafeTestDatabase` を投げて止める。
元の事故：コンテナの環境変数（`docker run --env-file`・`-e DB_DATABASE=…`・compose の `environment`）は `$_SERVER` に入り、Laravel はそれを先に読むので、`phpunit.xml` の `<env … force="true"/>` より勝つ。
開発用 DB に向けたままテストを流すと、`RefreshDatabase` が中身をすべて消す。

## 最短の手順

1. テスト用 DB の名前に、**語として** `test` か `testing` を入れる（`app_test`・`testing`・`app-test-1`。`latest`・`contest` は語でないので不可）。区切りは `_` `.` `-`。
2. 使うアプリの CI の DB 名にも印を付ける。
3. 並列の試し（`php artisan test --parallel`）でも、プロセスごとの DB 名（`app_test_test_1`）に印が残るので、そのまま通る。
4. 印の語を変えるなら `config/foundation.php`：

```php
'test_safety' => ['markers' => ['test', 'testing', 'spec']],
```

テストで読み取りだけに使う外の DB（印のない名前）は、**名前を偽らずに**接続名で外す：

```php
'test_safety' => ['ignore_connections' => ['legacy_readonly']],
```

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
