# 生の IP を残さない

名前空間 `Miraqceed\Foundation\Privacy`。訪問者の IP を、DB・キャッシュの表にそのまま（または秘密のないハッシュで）残さないための部品。**入れただけでは変わらない**（3つの設定は既定でどれも効かない。`IpHash`・`RawIp` は呼んだときだけ働く）。

- `foundation.privacy.sessions_without_ip`：`database` のセッションの表に IP を書かない（`DatabaseSessionHandlerWithoutIp`）
- `foundation.privacy.keyed_rate_limiter`：回数の制限の鍵を、すべて `APP_KEY` の HMAC にしてキャッシュに入れる（`KeyedRateLimiter`）
- `IpHash::of()`・`IpHash::ofBucket()`：DB に残す IP の値を、`APP_KEY` と別の鍵（`foundation.privacy.ip_hash_key`）の HMAC-SHA256 にする
- `Testing\RawIp::assertNotStored()`：試しの DB に、送った IP が残っていないかを確かめる試しの助け
- `foundation:deploy-check` の `session_ip`・`limiter_keys`・`ip_hash_key`

パッケージ自身の部品（ログインのロック・試みの数え・CSP の違反の報告の口）は、もともと IP を残さない（鍵は `AttemptKey` の HMAC。`docs/attempts.md`）。この部品は、Laravel・Fortify・アプリが残す所を塞ぐ。

## いつ使うか

個人の情報の方針で「生の IP を保存しない」と決めたアプリ。自分で書くと次を踏む。

- Laravel の `database` のセッションは、**ログインしていない訪問者も含めて、Web の要求のたびに** `sessions.ip_address` に IP を書く
- Laravel の `RateLimiter` は鍵をハッシュにしない。`database` のキャッシュでは、鍵がそのまま `cache` の表に残る
  - 数だけの `throttle:60,1` は、ログインしていない人の鍵が `sha1(ドメイン|IP)`
  - 名前の付いた制限（`throttle:login` など）は `md5(名前.鍵)`。`->by($request->ip())` と書けば、秘密のない md5
  - Fortify の `LoginRateLimiter`（`fortify.limiters.login` を空にしたとき）は、`メールアドレス|IP` をそのまま鍵にする
  - IPv4 は 2^32 通りしかないので、秘密のないハッシュは総当たりで IP に戻る
- 同じ回線の登録を数えるために、IP を秘密のない sha256 で列に残す。日をまたいで比べる値の鍵を `APP_KEY` にして、漏れたときに入れ替えると、数えが黙って切れる

## セッション（`sessions_without_ip`）

```php
// config/foundation.php
'privacy' => ['sessions_without_ip' => true],
```

- `SESSION_DRIVER=database` の handler を、`ipAddress()` が `null` を返すものに差し替える。`user_id`・`user_agent`・`payload`・`last_activity` は Laravel のまま書く
- **driver の名前は `database` のまま**（`SESSION_DRIVER` を替えない。`Accounts\PasswordSessions`・`foundation:deploy-check` も今のまま効く）。`session.encrypt` もそのまま効く
- アプリのプロバイダーが自分で `Session::extend('database', …)` を書いていれば、アプリが勝つ（パッケージのプロバイダーが先に起動する）
- 設定を使わず、アプリが `Session::extend('database', …)` に `DatabaseSessionHandlerWithoutIp` を渡してもよい。`user_agent` も消したいアプリは、このクラスを継いで `userAgent()` を書く

## 回数の制限の鍵（`keyed_rate_limiter`）

```php
// config/foundation.php
'privacy' => ['keyed_rate_limiter' => true],
```

- 容器の `RateLimiter` を `KeyedRateLimiter` に差し替える。`RateLimiter` のどの口も鍵を `cleanRateLimiterKey()` に通すので、Laravel の `throttle`・Fortify・アプリの `RateLimiter::hit()`・パッケージの数えの鍵が、キャッシュにはすべて `foundation-limiter:` と HMAC-SHA256 の16進64字で入る
- 鍵は `APP_KEY` から用途の名前で導く。`APP_KEY` が空なら `RuntimeException`（回数の制限のある経路は 500 になる。黙って生の鍵に戻さない）
- 差し替える前に `RateLimiter::for()` で登録した名前の付いた制限は、引き継ぐ
- Redis の `throttle`（`ThrottleRequestsWithRedis`）は `RateLimiter` を通らないので、この設定では変わらない

有効にしないアプリでも、自分の制限の鍵は `AttemptKey` で作れる（キャッシュには HMAC だけが入る）：

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Login\IpBucket;

RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by(AttemptKey::for('contact', IpBucket::of($request))));
```

数だけの `throttle:60,1` と、`->by($request->ip())` の名前の付いた制限は、この形に書き換えるか、`keyed_rate_limiter` を有効にする。

## DB に残す IP のハッシュ（`IpHash`）

```php
use Miraqceed\Foundation\Privacy\IpHash;

$user->signup_ip_hash = IpHash::of($request->ip());          // HMAC-SHA256 の16進64字
$report->reporter_ip_hash = IpHash::ofBucket($request);       // IPv6 は /64（IpBucket と同じ単位）。IPv4 は of() と同じ値
```

```dotenv
# 32字以上の乱数（例：php -r 'echo bin2hex(random_bytes(32));'）。APP_KEY と別にする
FOUNDATION_IP_HASH_KEY=
```

- 鍵は `foundation.privacy.ip_hash_key`。空・32字より短ければ、呼んだところで `RuntimeException`（呼ばないアプリは何も要らない）。`foundation:deploy-check` の `ip_hash_key` が長さを見る
- IP の書き方の違い（IPv6 の大文字・`::` の省略・0 の省略）は、正規の形にそろえてから作る。IPv4 を埋め込んだ IPv6（`::ffff:203.0.113.7`・`64:ff9b::203.0.113.7`）は、その IPv4 と同じ値（デュアルスタックのソケット・プロキシの設定で `$request->ip()` の形が変わっても、比べが切れない）。IP として読めない値は `InvalidArgumentException`（文に値を入れない）
- `APP_KEY` は使わない。残す値は日をまたいで比べるので、漏れたときに入れ替える `APP_KEY` に結び付けない
- **ハッシュにしても、個人に関する情報であることは変わらない**（同じ回線を日をまたいで結び付けられる）。保存の期間を決め、消す決まりの台帳（`docs/retention.md`）に書く

## 有効にすると変わること

- **`sessions_without_ip`：利用者に見える変わり。** `sessions.ip_address` を見せる画面（Jetstream の「ほかのブラウザのセッション」など）で、IP の欄が空になる。端末の名前（`user_agent`）は残る。IP を見せ続けたいアプリは、有効にしない
- **`keyed_rate_limiter`：切り替えた時点で、今までの数え（ログインのロックを含む）が一度空になる**（鍵が変わり、前の鍵の行を読まなくなる）。ロック中の人は解け、窓の中の回数も0から数え直す。避けたいなら、人の少ない時間に切り替える。無効に戻したときも、`APP_KEY` を入れ替えたときも、同じく空になる
- **`ip_hash_key`：入れ替えると、日をまたいだ比べが切れる**（入れ替えた後の値は、同じ回線でも前の値と一致しない）。生の IP を持たないので、前の値を新しい鍵で作り直すこともできない。前の鍵も試す口は持たない
  - ふだんは入れ替えない。漏れたときだけ入れ替え、数えの窓（同じ回線の1日・30日の登録の数えなど）の長さの間は、同じ回線の数えが少なく出ることを受け入れる
  - 気になるなら、前の値の列を `null` にして「比べられない」とはっきりさせる

## 有効にする前の行

- セッション：使っている行は、次の要求で `ip_address` が `null` に書き直される。来なくなった人の行は期限で消えるまで残るので、有効にした後に1回流す：

```sql
UPDATE sessions SET ip_address = NULL;
```

- キャッシュ：前の鍵の行は、期限が切れた後に `php artisan foundation:cache-prune` で消える（`database` のキャッシュは、期限切れの行を自分では消さない）

## 試しで確かめる（`RawIp`）

```php
use Miraqceed\Foundation\Testing\RawIp;

$this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('/contact', [/* … */]);

RawIp::assertNotStored('203.0.113.7');   // すべての表の文字の列に、この IP の書き方が1つもない
```

- 今の接続の schema のすべての表の、文字の列（`char`・`varchar`・`text` の類・`json`、PostgreSQL の `inet`・`cidr`）を探す。`migrations` は見ない
- IP の書き方の揺れ（IPv6 の大文字・省略・全部の形、IPv4 を埋め込んだ IPv6）も見つける。長い数の一部（`203.0.113.70` の中の `203.0.113.7`）は当てない
- 落ちた文には表と列の名前だけを出す（値は出さない）
- 見つけないもの：秘密のない sha1・md5 にした値（`throttle` の鍵は `keyed_rate_limiter` で塞ぐ）、base64 にした値（`sessions.payload` の中など）、文字でない列

## この部品の外

- Web サーバー・CDN（Cloudflare など）のアクセスログ、Laravel Telescope・Pulse の記録
- ログの文の中の IP（`FailureReporter::redactIp()`。`docs/failures.md`）
- Turnstile へ送る IP（既定で送らない。`docs/turnstile.md`）

## しないこと

- `sessions.ip_address` に、IP のハッシュや丸めた IP を入れない（見せても意味がなく、個人に関する情報が残る）
- DB に残す IP のハッシュの鍵を `APP_KEY` にしない（入れ替えると比べが切れる）。秘密のない `sha256($ip)` で残さない
- `keyed_rate_limiter` を、人の多い時間に切り替えない（数えが一度空になる）
