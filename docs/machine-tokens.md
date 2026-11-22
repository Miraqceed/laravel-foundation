# 機械用のトークンと、その入れ替え

名前空間 `Miraqceed\Foundation\MachineTokens`。機械が繰り返し使う鍵（顧客のサーバーで動く手先・キューのワーカー・サービスの間の呼び出し）を、ハッシュだけで持ち、**止めずに入れ替える**。呼んだときだけ働く。表は有効にしたときだけ作る。

## いつ使うか・使わないか

使う：

- 機械が要求のたびに `Authorization: Bearer` で送る鍵で、定期的に入れ替えたいもの

使わない：

- **人が使う API キー**（利用者が画面で作って消すもの）：Sanctum を使う
- **一度だけ渡したい秘密**（入場券・機械に最初の鍵を受け取らせる合言葉）：`OneTimeCodes`（`docs/one-time-codes.md`）
- **パスワード**など人が決める値：この部品のハッシュ（SHA-256）は、乱数で作った約238ビットの秘密だから速いハッシュでよい

自分で書くと、次の穴を踏む：

- 平文で持つ
- 形を確かめずに DB を引く
- **新しい鍵を出したその場で切り替える。** 答えが届かなかった機械は新しい鍵を持たず、古い鍵の猶予が切れた所で締め出される
- 前の鍵で新しい鍵を取れる（漏れた古い鍵で入れ替えられる）
- 読んでから全部の列を書き戻す形で、同時の入れ替えと切り替えの片方の書き込みが消える

## 有効にする

```php
// config/foundation.php
'machine_tokens' => [
    'enabled' => true,   // 真のときだけ表のマイグレーションを読み込む
],
```

```bash
php artisan migrate
# 表のマイグレーションをアプリに写して使うなら
php artisan vendor:publish --tag=foundation-machine-token-migrations
```

**表（`foundation_machine_tokens`）はアプリの既定の接続に置く。** 対象ごとに1行で、今・入れ替え待ち・前の鍵を SHA-256 だけで持つ。

## 種類

```php
use Miraqceed\Foundation\MachineTokens\MachineKind;

// 名前（表に入る）・頭の印・入れ替えを促すまでの秒（null なら促さない）・前の鍵を残す秒・入れ替え待ちが効く秒
$agentKind = new MachineKind('server-agent', 'sag_', rotateAfterSeconds: 86_400);
$workerKind = new MachineKind('worker', 'wrk_', rotateAfterSeconds: null, graceSeconds: 300, pendingSeconds: 120);
```

- 鍵は「頭の印 + 英数字40字」（`OneTimeCodes` と同じ形）。頭の印は、小文字で始まり `_` で終わる 2〜17字（小文字の英数字。途中に `_` を入れない）
- `rotateAfterSeconds` は既定を持たない（使い所で違う）。今の鍵を出して（切り替えて）からこの秒を過ぎると、`authenticate()` が入れ替えを促す
- `graceSeconds`（既定 600）：切り替えた後、前の鍵を効かせる秒。0 なら切り替えと同時に効かない
- `pendingSeconds`（既定 600）：入れ替え待ちの鍵が効く秒。過ぎたら効かず、今の鍵は効いたまま
- 頭の印は `OneTimeCodes` の種類と重ねない（ログや走査で見分けられなくなる）
- 違う値は、作るときに `InvalidArgumentException`

## 出す・確かめる・入れ替える・失効させる

```php
use Miraqceed\Foundation\MachineTokens\MachineTokens;

$tokens = app(MachineTokens::class);

// 出す（管理者が入れ直すとき）。その対象の今・入れ替え待ち・前の鍵は、その場で置き換わる
$issued = $tokens->issue($agentKind, "server:{$server->id}");
$issued->token;      // 平文。この戻り値にだけある。1度だけ渡す

// 要求ごとに確かめる。認めなければ、理由を問わず null
$machine = $tokens->authenticate($agentKind, $request->bearerToken());
$machine?->subject;       // "server:7"
$machine?->shouldRotate;  // 入れ替えを促すか（機械に伝えるのはアプリ）
$machine?->viaPrevious;   // 前の鍵で認めたか

// 入れ替える（今の鍵のときだけ。新しい鍵は入れ替え待ちになる）
$new = $tokens->rotate($agentKind, $request->bearerToken());   // ?IssuedToken

// 失効させる（行を消す。どの鍵もその場で効かなくなる）
$tokens->revoke($agentKind, "server:{$server->id}");
```

入れ替えの流れ：

```
            rotate(今)                 入れ替え待ちを初めて使う
  今=A  ───────────────►  今=A・待ち=B  ──────────────────────►  今=B・前=A（graceSeconds の間だけ）
                           │ 答えが届かなくても A は効く
                           │ もう一度 rotate(A) → 待ち=C（B は効かなくなる）
                           │ 待ちが pendingSeconds を過ぎた → B は効かない（A は効く）
```

- `authenticate()` は、文字列でない・形が違う・頭の印が違う値では DB を引かない。無い・別の種類・前の鍵の猶予切れ・入れ替え待ちの期限切れ・失効・出し直された、のどれも同じく `null`
- 入れ替え待ちの鍵が初めて使われたら、`authenticate()` の中で切り替える
- `rotate()` は今の鍵でだけ通る。前の鍵（漏れたかもしれない）・入れ替え待ちの鍵では `null`
- `shouldRotate` は、今の鍵で、促す秒を過ぎ、期限の内の入れ替え待ちがないとき真。並んだ要求が続けざまに入れ替えて、互いの入れ替え待ちを消し合わないため。答えが届かなかったときは、入れ替え待ちの期限の後にまた促す
- 最後に使った時刻（`last_used_at`）は、前に書いてから60秒を過ぎたときだけ書く（機械の要求のたびに書き込みを増やさない）。入れ替えを止めた機械・使われない機械は、`last_used_at` と `current_issued_at` を見てアプリが失効させる
- 書き換えはどれも、読んだ値を条件に入れた UPDATE 1つ。同時の切り替え・入れ替え・出し直し・失効が並んでも、後から来た書き込みが先の書き込みを消さない（PostgreSQL・MySQL・SQLite のどれでも）
- 種類と対象は、字のとおりに比べる（MySQL では2つの列を `utf8mb4_0900_bin`、MariaDB は `utf8mb4_nopad_bin` にする。写したマイグレーションを書き換えるときも、この照合順序を残す）

## 受け取る側（機械）の手順

入れ替えで締め出されないのは、機械が次のとおりに動くときだけ。手順を配るときに書く。

1. 入れ替えを促されたら、入れ替えの口を1回呼ぶ（続けざまに呼ばない）
2. 新しい鍵を受け取ったら、**今の鍵と別に持つ**。次の要求から新しい鍵を使う
3. 新しい鍵が通ったら、今の鍵を捨てる
4. 新しい鍵が断られたら、今の鍵に戻る（入れ替えの答えが届かなかった・置き換わった・期限が切れた）
5. 1つの鍵を複数の機械で分け合わない（対象を機械ごとに分ける）
6. 鍵をファイルに書くときは、`umask 077` にして、書く先と同じ置き場の一時のファイル（`mktemp`）に書いてから `mv` で置き換える（`docs/one-time-codes.md` の「受け取る側が端末で受け取るとき」）

## 最初の鍵の渡し方

管理画面で `OneTimeCodes` の合言葉を出し、機械が受け取りの口で換える。受け取った後の処理の中で `issue()` を呼ぶ。

```php
$claimed = $codes->claim($enrollKind, $request->input('code'), function (ClaimedCode $claimed) use ($tokens, $agentKind) {
    return $tokens->issue($agentKind, (string) $claimed->subject)->token;
});
$token = $claimed?->result;
```

処理が後で投げると、合言葉の使った印も鍵の行も巻き戻り、合言葉はまた受け取れる（同じ接続の同じトランザクション）。受け取りの口の書き方（POST の本文・`no-store`・`Attempts` で包む）は `docs/one-time-codes.md`。

## 機械の口

```php
// routes/api.php（セッションを通さない。セッションのクッキーを返さない）
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/agent/metrics', MetricsController::class);
    Route::post('/agent/token', RotateTokenController::class);
});
```

```php
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\Counting;
use Miraqceed\Foundation\Attempts\Limit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;
use Miraqceed\Foundation\Login\IpBucket;

public function __invoke(Request $request, MachineTokens $tokens, Attempts $attempts): Response
{
    $machine = null;
    try {
        // 断られた要求を、接続元ごとの共有の枠で数える
        $attempts->attempt([
            new Limit(AttemptKey::for('agent-token', IpBucket::of($request)), 30, 60, Counting::FailuresShared),
        ], function () use (&$machine, $tokens, $request): bool {
            $machine = $tokens->authenticate(self::kind(), $request->bearerToken());

            return $machine !== null;
        });
    } catch (TooManyAttempts $e) {
        return self::text("試しが多すぎます\n", 429);
    }
    if ($machine === null) {
        return self::text("認められません\n", 401);   // 断りの文は1つ
    }

    // …仕事…

    return response()->noContent()->withHeaders(['X-Rotate-Token' => $machine->shouldRotate ? '1' : '0']);
}

private static function text(string $body, int $status): Response
{
    return new Response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
}
```

- 入れ替えの口の答え（新しい鍵の平文）にも、断りにも `Cache-Control: no-store` を付ける
- 鍵を URL に入れない（アクセスのログに残る）。`Authorization: Bearer` で送らせる
- 平文の鍵を、ログ・例外の文・監査の記録・flash に入れない（記録するなら種類と対象だけ）
- `IssuedToken` は、`var_dump()`・`print_r()`・`json_encode()`・ログの context では平文を伏せ、`serialize()` は `LogicException` で断る（キューのジョブ・キャッシュに入れない）。ただし `(array) $issued` などで読むことは防げない。**オブジェクトごとログ・JSON・答えに渡さない。平文は `->token` を1度だけ、答えに使う**
- 逆プロキシの後ろでは `trustProxies` にプロキシの番地を挙げる（`docs/login.md`）

## アプリのトランザクションの中で呼んだとき

呼んでよい。中はセーブポイントになり、外を巻き戻すと切り替えも戻る（入れ替え待ちは入れ替え待ちのまま残るので、締め出されない）。行のロックは外の確定まで残るので、機械の口の仕事を長いトランザクションで包まない。

行き詰まり・直列化の失敗の試し直しは、外側のトランザクションの中では行われない（例外が外へ出る。`null` にはならない）。`authenticate()`・`rotate()` の中のトランザクションはセーブポイントになり、Laravel は一番外でしか試し直さない。`issue()` も、中では行き詰まりを試し直さない（同じ対象への同時の INSERT の一意の索引の違反は、中でも試し直す）。PostgreSQL の REPEATABLE READ・SERIALIZABLE で同時に切り替えると起こりうる。試し直すなら、一番外の `DB::transaction($cb, $attempts)` で包む。

## 伏せる（`docs/redaction.md`）

頭の印を `Redactor` の形に足すと、ログ・外の AI に送る文から伏せられる。

```php
// config/foundation.php
'redaction' => [
    'patterns' => [
        'agent_token' => '/(?<![A-Za-z0-9])sag_[A-Za-z0-9]{40}(?![A-Za-z0-9])/',
    ],
],
```

## 消す

失効（`revoke()`）で行を消す。期限で消える行はないので、`model:prune` は要らない。対象が人の id を含むなら、その人を消すときにアプリが `revoke()` を呼ぶ（消す決まりの台帳では `handledByApp`）。

## 時刻

表の時刻は時差なしの `dateTime` で、UTC で書いて UTC で比べる。アプリの時間帯（`app.timezone`）を替えても、DB の接続の時間帯がずれていても、猶予と期限は動かない。

## 限界

- 漏れた今の鍵は、持ち主より先に入れ替えれば奪える（入れ替えは今の鍵を持つ人を信じる）。漏れたと分かれば `revoke()` か `issue()`
- 使われない機械の鍵は、自動では失効しない（`last_used_at` を見てアプリが失効させる）
- 同じ対象に同時に `issue()` したら、後に確定したものだけが効く
- 時刻は秒で切り捨てる。猶予と期限が1秒未満短くなることがある
- 断った要求の数えはこの部品の外（`Attempts`）
