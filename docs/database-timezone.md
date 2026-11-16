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
    'timezone' => env('APP_TIMEZONE', 'UTC'),   // 時間帯の名前（Asia/Tokyo など）で書く
],
'mysql' => [
    // …
    'timezone' => env('DB_TIMEZONE', '+00:00'),  // アプリの時間帯と同じ時差で書く（Asia/Tokyo なら DB_TIMEZONE=+09:00）
],
```

- **PostgreSQL は時間帯の名前で書く。** `+09:00` と書くと、PostgreSQL は POSIX の書き方と読み、UTC より 9 時間**西**（時差 −09:00）にする
- **MySQL は時差で書く**か、MySQL に時間帯の表を読み込む（読み込んでいないと、`Asia/Tokyo` のような名前で接続が落ちる）

## 確かめる

```bash
php artisan foundation:db-timezone                     # 既定の接続
php artisan foundation:db-timezone --connection=mysql  # 別の接続
```

- ずれていれば、接続の名前・アプリの時間帯・2つの時差を出して、終わりの値 1（配置の台本・CI で使う）
- そろっていれば 0。PostgreSQL・MySQL 以外の接続は「見ません」と出して 0
- 出力に DB のホスト・ユーザー名は出ない

コードから：`DatabaseTimezone::mismatch('pgsql')`（ずれていれば文、そろっていれば null）・`DatabaseTimezone::databaseOffset()`（DB の今の時差、秒）。

## 限界

- 時間帯の名前でなく、**今の**時差で比べる。夏時間のある時間帯では、切り替えの前後でだけ違って見える（またはそろって見える）ことがある
- 見るのは PostgreSQL と MySQL（MariaDB）だけ。SQLite は時刻を文字列で持ち、接続の時間帯がない
- 自動では確かめない。配置の台本や CI で、コマンドを流す
