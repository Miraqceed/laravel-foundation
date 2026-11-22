# DB のロックの助け

名前空間 `Miraqceed\Foundation\Database`（と試しの助け `Testing\HeldLocks`）。並んだ要求・毎分の処理・キューのワーカーが、同じものを同時に数えたり書いたりしないようにする助けと、自分で書くと踏む罠。どれも呼んだときだけ働く（設定の節・中間処理・コマンドはない）。

| 部品 | すること |
|---|---|
| `NamedLock` | 名前つきのロック。同じ名前の処理を、プロセスをまたいで1つずつ動かす |
| `LockWait` | 渡した処理の間だけ、行のロックを待つ時間に上限を付け、後で接続の値を元に戻す |
| `Transactions` | アプリのトランザクションの中かを見分ける（試しの `RefreshDatabase` の包みは外に数える） |
| `LockErrors` | 待ちの誤り・デッドロックを、SQLSTATE と DB の番号で見分ける |
| `Testing\HeldLocks` | 試しの助け：2本目の接続で行・名前を握ったまま、確かめたい処理を動かす |

## なぜ

- 行のロック（`lockForUpdate()`）は、行のある所しか並べられない。「同じ回線から1日に作れる数」のように、アカウントをまたいで数える上限は、どの行を握っても並ばない。名前つきのロックなら並べられる
- 共用サーバーの MySQL は、行のロックを50秒（`innodb_lock_wait_timeout` の既定）待つ。画面の要求や毎分の処理がその間止まる。上限を付けるなら、終わったら必ず元に戻す（キューのワーカーは、同じ接続で次の仕事を動かす）
- 名前つきのロックを外のトランザクションの中で取ると、中のトランザクションは savepoint になり、ロックが外の確定より先に放される。もう一方の要求が、確定していない数えを見て並ぶ
- 試しは `RefreshDatabase` が全体をトランザクションで包むので、`DB::transactionLevel()` で「外か」を確かめる作りは、試しの中だけ狂う

## 名前つきのロック `NamedLock`

```php
use Miraqceed\Foundation\Database\LockNotAcquired;
use Miraqceed\Foundation\Database\NamedLock;

try {
    // 待つのは10秒まで。work の戻り値を返す
    $links = app(NamedLock::class)->run('links-per-line:'.$lineHash, waitSeconds: 10, work: fn () => DB::transaction(function () {
        // 数えて、上限の内なら作る
    }));
} catch (LockNotAcquired) {
    abort(503);   // 取れなかった。409・503 などの断りにする
}

app(NamedLock::class)->run('invoice-number:2026', 10, fn () => …, connection: 'mysql');   // 接続を選ぶ（既定は既定の接続）
```

- **トランザクションの外でだけ呼べる。** アプリのトランザクションの中なら `LogicException`（SQLite でも。SQLite で流す試しが、本番の DB で落ちる呼び方を見逃さないため）。試しの `RefreshDatabase` の包みの中では動く。work の中でトランザクションを始める
- `waitSeconds` は0以上の整数。0 は待たない。取れなければ `LockNotAcquired`（`$e->waitSeconds`。文に名前は入らない）
- 放すのは work の後（work の中のトランザクションの確定の後）。work が投げても放す
- **放すのに失敗しても投げない。** 投げると、確定した処理が誤りに化け、送り直しで二重になる。`FailureReporter` で記録し、work の戻り値・例外をそのまま返す・投げる。その接続がトランザクションの外なら切る（接続が残ると、ロックはワーカーが終わるまで残る）
- 同じ名前を、ロックの中でもう一度 `run()` すると、すぐ取れる（取った回数だけ放す）

| DB | 取る | 放す |
|---|---|---|
| MySQL・MariaDB | `GET_LOCK(名前, 秒)` | `RELEASE_LOCK(名前)` |
| PostgreSQL | 0秒なら `pg_try_advisory_lock(鍵)`。1秒以上なら `lock_timeout` を秒にして `pg_advisory_lock(鍵)`（`lock_timeout = 0` は「限りなく待つ」なので、待たないときに使わない）。取る文は savepoint（外ならトランザクション）で包み、`lock_timeout` は元に戻す | `pg_advisory_unlock(鍵)` |
| SQLite | **取らない**（work をそのまま動かす。下の「限界」） | — |
| そのほか | `LogicException` | — |

どちらのロックも接続に付き、確定・巻き戻しでは放されない。接続が切れればサーバーが放す。

### 名前

渡した名前は、接続の DB の名前と合わせて sha256 にする。MySQL には `lf:` と16進の40字（43字）、PostgreSQL には先頭8バイトの整数を渡す。

- MySQL の名前は**サーバー全体で1つ**（共用サーバーのほかの DB・並べて流す試しの DB とぶつかる）で、64字まで。そのまま渡さない
- 名前の長さに上限はない。空・壊れた UTF-8 は `InvalidArgumentException`
- 名前に利用者の id・IP から作った値を入れてよい（DB・記録・例外の文には、元の名前を出さない）
- 調べるときは `NamedLock::serverName($name)` で DB に渡す名前（鍵）を得て、MySQL は `performance_schema.metadata_locks`（`OBJECT_TYPE = 'USER LEVEL LOCK'`）、PostgreSQL は `pg_locks`（`locktype = 'advisory'`）で探す

### 途中で接続が切れたとき

トランザクションの外で接続が切れると、Laravel は黙ってつなぎ直し、同じ文を流し直す。名前つきのロックは前の接続と一緒に失われ、work はロックなしで続く。防ぐことはできない。

- 放すときに「握っていなかった」と分かれば（MySQL の `RELEASE_LOCK` の 0・NULL、PostgreSQL の false）、記録する
- 長い処理をロックの中に入れない。ロックの中では、数えて書くだけにする（外への HTTP はロックの前か後）

## 行のロックを待つ時間 `LockWait`

```php
use Miraqceed\Foundation\Database\LockWait;

// 中では、行のロックを10秒まで待つ。後で（投げても）接続の値を元に戻す
$count = app(LockWait::class)->atMost(10, fn () => $this->processBatches());
```

- 秒は1以上の整数（MySQL の最小が1秒）。0 以下は `InvalidArgumentException`
- トランザクションの中でも外でも呼べる
- MySQL は `innodb_lock_wait_timeout`（`SET SESSION`。巻き戻しでは戻らないので、必ず後で戻す）、PostgreSQL は `lock_timeout`（ミリ秒）。SQLite は何もしない
- 設定できない（接続が落ちたなど）：work をそのまま動かし、記録する（1時間に1回）。work は落ちた接続に自分で出会う
- 戻せない：work の戻り値・例外を置き換えずに記録し（1時間に1回）、トランザクションの外なら接続を切る（次の接続はサーバーの既定で始まる。中で切ると黙って巻き戻り、その後が自動のコミットになるので切らない）
- PostgreSQL で、呼び手のトランザクションの中で work が待ちの誤りで落ちたときは、戻す文も通らないが、記録しない（巻き戻せば `SET` も消える）
- 記録の文には、例外のクラスと SQLSTATE だけを入れる（設定の値・SQL を入れない）

## トランザクションの中か `Transactions`

```php
use Miraqceed\Foundation\Database\Transactions;

Transactions::inside();             // 既定の接続で、アプリのトランザクションの中なら真
Transactions::inside('mysql');
Transactions::assertOutside('冪等キーは仕事のトランザクションの外で取る');   // 中なら LogicException（文はこの引数）
```

- 試しの `RefreshDatabase`・`DatabaseTransactions` の包みは数えない。試しのために段数を設定で合わせる作り（`config(['app.base_transaction_level' => DB::transactionLevel()])` など）は要らない
- ほかの接続のトランザクションは数えない
- 限界：PDO で直に `BEGIN` したトランザクションは見えない。`DatabaseTruncation` の試しは包まないので、`transactionLevel() > 0` と同じになる

## ロックの誤りの見分け `LockErrors`

```php
use Miraqceed\Foundation\Database\LockErrors;

LockErrors::isLockWaitTimeout($e);   // MySQL の 1205・PostgreSQL の 55P03
LockErrors::isDeadlock($e);          // MySQL の 1213・PostgreSQL の 40P01・SQLSTATE 40001
```

- 文の言葉でなく、SQLSTATE と DB の番号で見る。包まれた例外（入れ子のトランザクションの `DeadlockException`）は、前の例外をたどる
- **Laravel の `DB::transaction($callback, attempts: 3)` は、PostgreSQL の待ちの誤り（55P03）ではやり直さない**（Laravel の見分けに 55P03 が入っていない）。このパッケージは Laravel の見分けを替えない。やり直したいなら、一番外で `LockErrors::isLockWaitTimeout($e)` を見てやり直す

## 試しの助け `HeldLocks`

```php
use Miraqceed\Foundation\Testing\HeldLocks;

// 2本目の接続で行を FOR UPDATE で握ったまま、関数を動かす。関数の間、試しの接続の行のロックの待ちは1秒
HeldLocks::row('accounts', $account->id, function () use ($account) {
    $this->expectException(QueryException::class);   // LockErrors::isLockWaitTimeout() が真
    app(Charger::class)->charge($account);
});
HeldLocks::row('accounts', 'ABC-1', fn () => …, column: 'code');   // id でない列で選ぶ

// 2本目の接続で名前つきのロックを握ったまま、関数を動かす（中の NamedLock::run() は LockNotAcquired）
HeldLocks::name('invoice-number:2026', fn () => …);
```

- 握れるのは**コミットされた行だけ**。`RefreshDatabase` の中で作った行は2本目の接続から見えないので、分かる文の `LogicException` で止まる。`DatabaseTruncation` などで片付ける試しにする
- 関数が投げても返しても、2本目の接続を巻き戻し、ロックを放し、閉じる。試しの接続の待ちの上限も元に戻す
- SQLite では試しを飛ばす（`markTestSkipped`）
- PostgreSQL では、待ちの誤りでその接続のトランザクションが壊れる。試しの接続がトランザクションの中なら、巻き戻すまで使えない

## 自分で書くときの注意

- **ロックの順を固定する**：名前つきのロック → 行。行は id の順。逆の順で取る経路が1つでもあると、デッドロックになる
- **MySQL の待ちの誤り（1205）を捕まえて、同じトランザクションを続けない。** MySQL は誤った文だけを巻き戻し、トランザクションは開いたまま残る。続けて確定すると、前半だけが確定する。PostgreSQL は待ちの誤りでトランザクションが壊れ、巻き戻すまで何もできない。どちらも外へ投げ、一番外でやり直す
- **`forceIndex()` は DELETE・UPDATE で黙って消える**（Laravel は DELETE・UPDATE の文に索引の指定を入れない。MySQL は1つの表の DELETE に索引の指定を受け付けない）。消す行の主キーを `forceIndex()` つきの SELECT で選び、主キーで消す・更新する
- **名前つきのロックとキャッシュのロック（`Cache::lock()`）の選び分け**
  - 名前つきのロック：落ちたプロセスのロックを、サーバーがすぐ放す。DB の中で待つ。MySQL・PostgreSQL だけ
  - キャッシュのロック：どの DB でも、SQLite でも効く。`withoutOverlapping()` が使う。落ちたプロセスのロックは期限まで残る。`database` のキャッシュでアプリと同じ接続なら、**アプリのトランザクションの中で取らない**（ロックの行が確定まで見えず、ほかの要求はその行のロックで待つ。MySQL は待ちの誤りで落ち、PostgreSQL は待った後に「取れない」が返る）
- 接続を束ねる道具（PgBouncer の transaction の束ね方など）の下では、名前つきのロックも待ちの上限も、別の要求の接続に渡る。セッションごとの束ね方にするか、使わない
- パッケージのほかの部品（`Attempts`・`OnceNotices`）をアプリのトランザクションの中で呼ばない理由も、同じ仕組み（巻き戻しで数え・印も巻き戻る。`docs/attempts.md`・`docs/once-notices.md`）

## 限界

- **SQLite では守らない。** `NamedLock` はロックを取らずに work を動かし、`LockWait` は何もしない。SQLite は書き込みを1つずつにするので、1つのトランザクションの中で「数えて → 足す」なら、並んだ2つ目は重ねて数えずに `SQLITE_BUSY`（database is locked）で落ちる。ただし、**複数のプロセスが同時に書く使い方**（php-fpm の複数のワーカー・キューのワーカーと画面の要求など）で、トランザクションの外で数えた値を後で使う処理は並び、上限を超えうる。そういう使い方では MySQL か PostgreSQL を使う。SQLite は、試しと、書き手が1つの小さな環境のためのもの
- MariaDB は MySQL と同じ作りで動かすが、試しは MySQL 8.4 と PostgreSQL 16 で流している
- 既にある部品（`Attempts`・`OnceNotices`・`EmailChanges`・`OneTimeCodes`・`Erasure`）は、行のロックを DB の既定の時間だけ待つ。短くしたいなら、アプリが呼ぶ所を `LockWait::atMost()` で包む
