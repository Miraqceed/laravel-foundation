# DB の接続の時間帯

`Miraqceed\Foundation\Support\DatabaseTimezone` とコマンド `foundation:db-timezone`。DB の接続の時間帯が、アプリの時間帯（`app.timezone`）とずれていないかを確かめる。呼んだときだけ働く（自動では確かめない）。

## なぜ

Laravel は日時を時差なしの文字列（アプリの時間帯の時刻）で DB に書く。DB の接続の時間帯がアプリと違うと、時差を持つ列に時差の分ずれた時刻が入る。

- PostgreSQL の `timestamptz`：時差なしの文字列を、接続の時間帯（`TimeZone`）の時刻と読む
- MySQL の `TIMESTAMP`：接続の時間帯（`time_zone`）から UTC に直して持つ。`NOW()` も接続の時間帯

例：アプリが `Asia/Tokyo`、PostgreSQL の接続が UTC だと、`now()` で書いた時刻が 9 時間先の時刻として入る（予約・期限・次に動く時刻が 9 時間ずれる）。Laravel の雛形の `config/database.php` は pgsql・mysql の接続に `timezone` を書いていないので、`APP_TIMEZONE` を替えると、ずれたまま気付きにくい。

## そろえ方

```php
// config/database.php
'pgsql' => [
    // …
    'timezone' => 'Asia/Tokyo',   // app.timezone と同じ時間帯の名前を書く
],
'mysql' => [
    // …
    'timezone' => '+09:00',       // 夏時間の無い時間帯なら、app.timezone と同じ時差で書いてよい
],
```

- **`app.timezone` と同じ値を書く。** `config/app.php` が `env('APP_TIMEZONE')` を読んでいるなら、ここも `env('APP_TIMEZONE', 'UTC')` と書いてよい（雛形の `config/app.php` は時間帯を決め打ちしていることがあるので、読んでいるかを先に確かめる）
- **PostgreSQL は時間帯の名前で書く。** `+09:00` と書くと、PostgreSQL は POSIX の書き方と読み、UTC より 9 時間**西**（時差 −09:00）にする
- **MySQL を時差で書いてよいのは、夏時間の無い時間帯（`Asia/Tokyo`・`UTC` など）だけ。** 夏時間のある時間帯（`America/New_York`・`Europe/London` など）は、時差が季節で替わるので、時差で書くと一年の片方の季節だけずれる。名前で書き、MySQL に時間帯の表を読み込む（読み込んでいないと、`Asia/Tokyo` のような名前で接続が落ちる）

## 確かめる

```bash
php artisan foundation:db-timezone                     # 既定の接続
php artisan foundation:db-timezone --connection=mysql  # 別の接続
```

- ずれていれば、接続の名前・アプリの時間帯・2つの時差を出して、終わりの値 1（配置の台本・CI で使う）
- そろっていれば 0。PostgreSQL・MySQL 以外の接続は「見ません」と出して 0
- 確かめられなければ 2：つながらない（接続の名前と例外の種類だけを出す）・`--connection=` の値が空（既定の接続と読まない）
- 出力に DB のホスト・ユーザー名・DB の名前は出ない（つながらないときの例外の文も出さない）

コードから：`DatabaseTimezone::mismatch('pgsql')`（ずれていれば文、そろっていれば null）・`DatabaseTimezone::databaseOffset()`（DB の今の時差、秒）。

## 限界

- 時間帯の名前でなく、**今の**時差で比べる。夏時間のある時間帯で、接続を時差で書いた（またはアプリと別の時間帯の名前にした）ときは、**一年のうち片方の季節だけ**ずれに見える（またはそろって見える）。例：アプリが `America/New_York`、MySQL の接続が `-05:00` なら、冬はそろって見え、夏は 1 時間のずれに見える。アプリが `Europe/London`、接続が UTC なら、冬のあいだはずっとそろって見える。夏時間のある時間帯は、接続も同じ名前で書く（名前が同じなら、季節を問わずそろう）
- 見るのは PostgreSQL と MySQL（MariaDB）だけ。SQLite は時刻を文字列で持ち、接続の時間帯がない
- 自動では確かめない。配置の台本や CI で、コマンドを流す
