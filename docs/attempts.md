# 試みの数え（ログイン以外の照合）

名前空間 `Miraqceed\Foundation\Attempts`。呼んだときだけ働く。数えはキャッシュ（`RateLimiter`・`Cache`）で、Redis は要らない。ログインのロック（`docs/login.md`）も、この部品の上で動く。

## いつ使うか

ログイン以外の照合の失敗を数えて止めるとき。確認コード・使い捨ての合言葉の受け取り・現在のパスワードの入れ直し・カードの登録など。**数えを自前で作らない。**

自分で書くと、ほとんどが「数えを見る → 照合する → 失敗なら足す」の形になる。照合を待つ間に並んだ要求が、揃って「まだ上限の手前」と判断されて照合に進み、上限より多く試せる。この部品は**照合の前に1回分を足し、足した後の数で判断する**。照合が例外を投げたときは、足した分を返さない（例外を誘発して数えさせない抜け道を作らない）。

## 窓のある数え（`attempt`）

```php
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Login\IpBucket;

// 入力と権限の確かめを通った後に、照合そのものを包む
try {
    $ok = app(Attempts::class)->attempt([
        // 接続元ごとに 1分10回。成功しても今回の1回分だけ返す（共有の枠）
        new Limit(AttemptKey::for('token-claim', IpBucket::of($request)), 10, 60, Counting::FailuresShared),
    ], fn () => $claims->claim($code) !== null);
} catch (TooManyAttempts $e) {
    // 照合には進んでいない。$e->retryAfter 秒後に再び試せる（1以上）
    abort(429);
}
```

1つの試みに、枠を何本でも渡せる。どれか1本が上限なら照合しない。

### 数え方の選び方（`Counting`）

| 数え方 | 成功したとき | 使う所 |
|---|---|---|
| `FailuresOwn`（既定） | 枠を空にする | 本人の枠（アカウントごとの確認コード・現在のパスワード） |
| `FailuresShared` | 今回の1回分だけ返す | 共有の枠（接続元ごと）。空にすると、自分のアカウントを持つ攻撃者が合間に成功するだけで枠を戻せる |
| `Every` | 返さない | お金の掛かる試み（カードの登録）。結果を待たずに数える。結果の後に数えると、同時の要求がどれも上限の手前で通る |

### 例：カードの登録を、その日の終わりまで1日5回にする

```php
$secondsUntilEndOfDay = (int) now()->diffInSeconds(now()->endOfDay(), true) + 1;

app(Attempts::class)->attempt([
    new Limit(AttemptKey::for('card', (string) $user->id), 5, $secondsUntilEndOfDay, Counting::Every),
    new Limit(AttemptKey::for('card-ip', IpBucket::of($request)), 10, $secondsUntilEndOfDay, Counting::Every),
], fn () => $gateway->registerCard($user, $token));
```

## 続けた失敗（`attemptConsecutive`）

確認コード（TOTP など）のように、時間をかければいつまでも試せては困る照合に使う。

- 窓のある数え（「30分に10回」）では、30分おきに9回ずつ試せば、いつまでもロックされない
- 続けた失敗は**時間で消えない数え**で数え、成功で空にする。上限に達したらロックの時間だけ照合せずに断る。明けた後は1回だけ照合し、失敗ならまたロックする（上限からは、間違えるたびにロック）
- 照合の例外は失敗と同じに扱う（ロックは残り、ロックの時間で明ける）

```php
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;

// 上限とロックの秒数は設定（foundation.attempts.consecutive。既定 10回・1800秒）から
$limit = ConsecutiveLimit::fromConfig(AttemptKey::for('totp', (string) $user->id));

try {
    $ok = app(Attempts::class)->attemptConsecutive($limit, fn () => $verifier->verify($user, $code));
} catch (TooManyAttempts $e) {
    // ロック中。$e->retryAfter はロックが明けるまでの秒数
}
```

## 鍵（`AttemptKey`）

- `AttemptKey::for(名前, ...値)` は、値を `APP_KEY` の HMAC-SHA256 にして鍵を作る。キャッシュの表の鍵から、IP・メールアドレスを総当たりで戻せない
- 名前（`token-claim`・`card` など）は鍵にそのまま入る。IP・メールアドレスを名前に入れない。口ごとに名前を分ける（再設定の申請と設定の数えを分ける、など）
- `APP_KEY` が空なら `RuntimeException`（黙って秘密のない鍵に戻さない）。`APP_KEY` を入れ替えると、鍵が変わって数えが消える

## 診断の間に外す（`bypass`）

脆弱性の診断の道具は、回数の制限で弾かれた要求を失敗とみなさず、認証の経路の検査が黙って抜けることがある。診断の間だけ、`foundation.attempts.bypass` を真にすると、数えずに照合する（ログインのロックも）。

- **`production` の環境では効かない。** 真でも数え続け、`report()` で知らせる（1時間に1回）

## してはいけないこと

- 照合の数えを自前で作らない（見てから照合の後に足す形にしない）
- 経路の手前（中間処理）で数えない。入力の確かめ・権限の確かめを通った後に照合を包む。手前で数えると、同じ接続元の人の入力の誤りで正当な利用者が止まる
- 確認コードを窓のある数えだけで守らない（続けた失敗で数える）
- お金の掛かる試みを、失敗だけで数えない（`Every`）
- 逆プロキシの後ろで `trustProxies` を設定せずに接続元の枠を使わない（全員が同じ接続元になる。`docs/login.md`）
- **アプリの `DB::transaction` の中で呼ばない**（`database` のキャッシュがアプリと同じ接続のとき）。照合に失敗してトランザクションを巻き戻すと、足した数えも巻き戻り、上限より多く照合できる。トランザクションの外で呼ぶか、キャッシュに別の接続（`cache.stores.database.connection`）を使う

## 限界

- 数えはキャッシュにある。キャッシュを消せば数えも消える。`APP_KEY` を入れ替えても消える
- 守りは、キャッシュの `add`（無ければ作る）と `increment` が原子的であることに頼る。数えは `add` と `increment` だけで足し、足した数を書き直さない（Laravel の `RateLimiter::increment` は、窓の始まりに並んだ要求の1回分を書き直しで消すことがあるので使わない）。回数の制限には `database`（Laravel 11 からの新しいアプリの既定）か `redis` などを使う

| キャッシュ | この部品の守り |
|---|---|
| `database`・`redis`・`memcached` | 効く（`add` と `increment` が原子的。PostgreSQL・MySQL の `database` のキャッシュで、別々のプロセスを並べて確かめている） |
| `file` | 弱まる。`increment` がロックを取らずに読んでから書き直すので、同時の要求で足した分が消え、上限より多く照合しうる（ロックの印の `add` は効く） |
| `array` | 効かない。1つのプロセスの中だけで、要求をまたいで数えない（試しのためのもの） |
- `database` のキャッシュがアプリと同じ接続のとき、アプリのトランザクションを巻き戻すと数えも巻き戻る（「してはいけないこと」）
