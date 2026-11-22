# 1回だけ使える合言葉

名前空間 `Miraqceed\Foundation\OneTimeCodes`。一度だけ渡したい秘密（機械にトークンを受け取らせる合言葉・1回限りの入場券・確認のリンクの秘密）を、期限つきで、ハッシュだけで持ち、同時の要求でも1回だけ通す。呼んだときだけ働く。表は有効にしたときだけ作る。

## いつ使うか・使わないか

使う：

- 管理画面で合言葉を出し、別の機械がそれをトークンに換える（受け取る）
- 入口の URL から別の画面へ移るときの、短い間だけ効く入場券
- 確認のリンクの中の秘密（1回だけ効けばよいもの）

使わない：

- **繰り返し使う API のトークン**：人が使う API キーは Sanctum、機械が使い止めずに入れ替えたい鍵は `MachineTokens`（`docs/machine-tokens.md`）を使う（受け取った後の処理の中で `createToken()`・`MachineTokens::issue()` を呼ぶ。下の例）。Sanctum のトークンを使った後に消して1回だけにする形は、確かめが読むだけなので、同時の2つの要求がどちらも通る
- **パスワードの再設定**：Laravel の仕組み（`password_reset_tokens`）を使う
- **期限の間は何度開いてもよいリンク**：署名つきの URL（`URL::temporarySignedRoute`）で足りる
- **人が決める合言葉（パスワード）**：この部品のハッシュ（SHA-256）は、乱数で作った約238ビットの秘密だから速いハッシュでよい。人が決める値には使わない

自分で書くと、次の穴を踏む：平文で持つ／読んでから「使っていない」と判断して印を書く2段の形で、同じ合言葉が同時に2回通る／断りの理由を分けて、合言葉が実在したかを外から確かめられる／形を確かめずに DB を引く／答えがキャッシュに残る／トークンを出せなかったのに、合言葉だけが使われた扱いになる。

## 有効にする

```php
// config/foundation.php
'one_time_codes' => [
    'enabled' => true,   // 真のときだけ表のマイグレーションを読み込む
    'prune_days' => 7,   // 期限・使った時刻・失効の時刻のどれかがこの日数より前の行を消す
],
```

```bash
php artisan migrate
# 表のマイグレーションをアプリに写して使うなら
php artisan vendor:publish --tag=foundation-one-time-code-migrations
```

**表（`foundation_one_time_codes`）はアプリの既定の接続に置く。** 受け取った後の処理（トークンを出すなど）の書き込みも同じ接続で行う。同じ接続だから、使った印とその書き込みが1つのトランザクションに入る。

## 種類

合言葉の種類を1つずつ決める。受け取りは、種類が合うものだけを通す（別の種類の合言葉は「無い」と同じに断る）。

```php
use Miraqceed\Foundation\OneTimeCodes\CodeKind;

// 名前（表に入る）・頭の印・効く秒数（既定 600）
$claimKind = new CodeKind('token-claim', 'apc_');
$ticketKind = new CodeKind('entry-ticket', 'et_', 60);
```

- 頭の印は、小文字で始まり `_` で終わる 2〜17字（小文字の英数字。途中に `_` を入れない）。合言葉は「頭の印 + 英数字40字」になる
- 頭の印は、人と道具が「これは秘密だ」と見分けるため。秘密の走査（下の「伏せる」）にも使う
- 違う値は、作るときに `InvalidArgumentException`

## 出す・受け取る・失効させる

```php
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;

$codes = app(OneTimeCodes::class);

// 出す。対象（例：機械や利用者の id）と添えるもの（受け取ったときに返す。秘密を入れない）
$issued = $codes->issue($claimKind, "machine:{$machine->id}", ['name' => $machine->name]);
$issued->code;       // 平文。この戻り値にだけある。1度だけ渡す
$issued->expiresAt;  // 期限（アプリの時間帯）。この時刻からは受け取れない

// 受け取る。受け取れなければ、理由を問わず null
$claimed = $codes->claim($claimKind, $request->input('code'));
$claimed?->subject;  // "machine:7"
$claimed?->data;     // ['name' => ...]

// 失効させる（同じ種類・同じ対象の、まだ使っていないもの）。失効させた数を返す
$codes->revoke($claimKind, "machine:{$machine->id}");
```

- **出し直すと、同じ種類・同じ対象の前の合言葉は効かなくなる**（同じトランザクションで失効させる）。対象が null なら何も失効させない
- 受け取りは、文字列でない・形が違う・頭の印が違う値では DB を引かない。無い・別の種類・切れた（期限ちょうども）・使った・失効した・同時の要求に競り負けた、のどれも同じく `null`
- 1回だけ通すのは、期限・使った・失効・種類を条件に入れた UPDATE 1つ。同時に同じ合言葉が来ても、通るのは1つだけ（PostgreSQL・MySQL・SQLite のどれでも）。分離の水準を REPEATABLE READ・SERIALIZABLE に上げた接続で競り負けた側も、例外でなく `null`（受け取りのトランザクションを3回まで試し直す）
- 別々の対象に同時に出しても、行き詰まらない（失効させる行は、ロックを取らない読み出しで引いてから id で失効させる。対象の条件で UPDATE すると、MySQL は索引の隙間をロックし、同時の INSERT どうしが互いを待って `1213 Deadlock` になる）
- 種類と対象は、字のとおりに比べる（大文字・小文字・アクセント・後ろの空白を区別する）。MySQL では2つの列を `utf8mb4_0900_bin`（MariaDB は `utf8mb4_nopad_bin`）にする。既定の照合順序では `user:abc` に出し直すと `USER:ABC` の合言葉まで失効する。写したマイグレーションを書き換えるときも、この照合順序を残す
- 添えるもの（`data`）は JSON にして持ち、受け取ったときに配列に戻す。PHP の値のままは戻らない：`2.0` は `2` になり、MySQL の JSON の列ではキーの順が替わることがある。型や順に頼らない

### 受け取った後の処理

3つ目の引数で渡す。使った印を書いたのと同じトランザクションの中で動き、戻り値は `$claimed->result` に入る。

```php
$claimed = $codes->claim($claimKind, $code, function (ClaimedCode $claimed) {
    $machine = Machine::findOrFail((int) substr((string) $claimed->subject, strlen('machine:')));

    return $machine->createToken($machine->name)->plainTextToken;   // Sanctum
});

$token = $claimed?->result;
```

- 処理が投げたら、使った印も巻き戻り、合言葉はまた受け取れる（例外はそのまま出る）
- 処理が `null` を返しても、受け取れたかは `$claimed` が `null` かどうかで分かる
- **巻き戻るのは、同じ接続の DB の書き込みだけ。** 別の接続への書き込み・キューへの投入・メール・外の API の呼び出しは巻き戻らない。それらの後で処理が失敗すると、合言葉はまた受け取れるので、トークンが2つ出うる。外への作用は `DB::afterCommit()`（キューのジョブなら `afterCommit`）でコミットの後に回す
- アプリの `DB::transaction` の中で `claim()` を呼ぶと、受け取りはセーブポイントになる。外側を巻き戻すと、使った印も巻き戻り、合言葉はまた受け取れる。行き詰まり・直列化の失敗の試し直しも、外側のトランザクションの中では行われない（例外が外へ出る）

## 受け取りの口

機械が合言葉を送ってトークンを受け取る口の例：

```php
// routes/api.php（セッションを通さない。セッションのクッキーを返さない）
Route::post('/tokens/claim', ClaimTokenController::class);
```

```php
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Login\IpBucket;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCodes;

public function __invoke(Request $request, OneTimeCodes $codes, Attempts $attempts): Response
{
    $claimed = null;
    try {
        // 受け取る前は本人が分からないので、接続元ごとの共有の枠で数える
        $attempts->attempt([
            new Limit(AttemptKey::for('token-claim', IpBucket::of($request)), 10, 60, Counting::FailuresShared),
        ], function () use (&$claimed, $codes, $request): bool {
            $claimed = $codes->claim(self::kind(), $request->input('code'), fn ($c) => $this->issueToken($c));

            return $claimed !== null;
        });
    } catch (TooManyAttempts $e) {
        return self::text("試しが多すぎます。{$e->retryAfter} 秒待ってからやり直してください\n", 429);
    }

    return $claimed === null
        ? self::text("受け取れませんでした。合言葉が違うか、期限を過ぎたか、もう使われています\n", 400)
        : self::text($claimed->result."\n", 200);
}

private static function text(string $body, int $status): Response
{
    return new Response($body, $status, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'no-store',   // 平文の答えも断りも、間の装置・ブラウザーに残さない
    ]);
}
```

- **合言葉は POST の本文で受ける。** URL に入れない（アクセスのログに残る）。GET で消費しない（メールのスキャナーがリンクを開く）。リンクで渡すときは、GET は確かめの画面だけを出し、ボタンの POST で受け取る
- **断りの文は1つにする。** 「切れています」「もう使われています」と分けると、合言葉が実在したかを確かめられる
- 数えは `Attempts` で行う（`docs/attempts.md`）。合言葉は約238ビットあるので、数えは当てられるのを防ぐためより、**DB を引く回数と記録の騒がしさを抑えるため**にある（外してよいという意味ではない）。形の違う値も数えに入る
- ログインした本人が受け取る口なら、本人の枠（`Counting::FailuresOwn`）も使える
- `attempt()` をアプリの `DB::transaction` の中に置かない（受け取りは自分のトランザクションを持つ。`database` のキャッシュが同じ接続だと、外側を巻き戻したときに数えも巻き戻る）
- 逆プロキシの後ろでは `trustProxies` にプロキシの番地を挙げる（しないと全員が同じ接続元になる。`at: '*'` にしない。`docs/login.md`）

## 平文を出す画面

管理画面で合言葉を出して見せるとき：

- **リダイレクトと flash で渡さない。** その要求の答え（200 の画面）に直に出す。セッションは DB に暗号にせずに置かれることがある
- 答えに `Cache-Control: no-store` を付ける
- 監査の記録・ログ・例外の文に平文を入れない（記録するなら種類と対象だけ）

## 伏せる（`docs/redaction.md`）

頭の印を `Redactor` の形に足すと、ログ・外の AI に送る文から伏せられる。

```php
// config/foundation.php
'redaction' => [
    'patterns' => [
        'claim_code' => '/(?<![A-Za-z0-9])apc_[A-Za-z0-9]{40}(?![A-Za-z0-9])/',
    ],
],
```

## 受け取る側が端末で受け取るとき

合言葉を `curl` などで送り、トークンをファイルに書く手順を配るときの注意：

- `umask 077` にして、書く先と同じ置き場の一時のファイル（`mktemp`）に書き、確かめてから `mv` で置き換える（途中で失敗しても、前のファイルを壊さない）
- トークンの形の本文を画面に出さない（断りの文だけを出す）
- コマンドの頭に空白を1つ置く（`HISTCONTROL=ignorespace` の端末では履歴に残らない）

## 消す

```php
// routes/console.php（パッケージは schedule に入れない）
Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\OneTimeCodes\OneTimeCode::class]])->daily();
```

期限・使った時刻・失効の時刻のどれかが `prune_days` 日（既定 7。1未満は1）より前の行を消す。使った行を少し残すのは、「もう使われています」と言われた人に、いつ使われたかを見せられるようにするため。

## 「効くものは1つ」を DB で守りたいとき

アプリの表（例：機械ごとのトークン）で「同じ対象に効くものは1つ」を DB で守るとき、部分の一意の索引（`... WHERE revoked_at IS NULL`）は MySQL にない。3つの DB で動く書き方：

```php
$table->string('live_key')->nullable()->unique();   // 効いている間は対象の値、失効したら null
```

PostgreSQL・MySQL・SQLite のどれも、一意の索引で null どうしを同じと見ないので、失効した行はいくつあってもよい。失効させるときに `live_key` を null にする。

この部品の表は、出すときの失効で「効くものは1つ」を保つ（索引は使わない）。

## 時刻

表の時刻は時差なしの `dateTime` で、UTC で書いて UTC で比べる。アプリの時間帯（`app.timezone`）を替えても、DB の接続の時間帯がずれていても、期限は動かない。返す時刻（`expiresAt`・`issuedAt`）はアプリの時間帯に直す。

## 限界

- 同じ種類・同じ対象に**同時に**2つ出すと、2つとも効くことがある（部分の一意の索引を使わないため。どちらも正当に出したもの）
- 時刻は秒で切り捨てる。効く間が1秒未満短くなることがある
- 失敗の数えはこの部品の外（`Attempts`）
- 表の行は自動では消えない（`model:prune` を schedule に入れる）
