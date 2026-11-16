# AI の費用の上限と記録

名前空間 `Miraqceed\Foundation\Ai`。**既定は無効**。入れただけでは挙動も表も変わらない。Redis・常駐のプロセスは要らない。[Laravel AI SDK](https://github.com/laravel/ai)（`laravel/ai` ^1.4）で呼ぶ AI にだけかかる。

## いつ使うか

AI の呼び出しの不具合（ループ・再試行の暴走）や想定外の利用で、請求が膨らむのを止める。**アプリ全体**の1日・1か月の金額と1日の回数に上限をかけ、呼び出しごとの使用量と原価を残す。利用者ごと・組織ごとの上限は作らない。

SDK が呼び出しのたびに出す知らせを聞くので、エージェントにミドルウェアを書かなくても、すべての呼び出しにかかる（偽の答え `Agent::fake()` でも同じ）。

## 最短の手順

1. SDK を入れる（パッケージの `suggest`。必須の依存ではない）：

```bash
composer require laravel/ai
```

2. 有効にして、上限と単価を決める：

```php
// config/foundation.php
'ai' => [
    'enabled' => true,
    'daily_limit_yen' => env('FOUNDATION_AI_DAILY_LIMIT_YEN'),
    'monthly_limit_yen' => env('FOUNDATION_AI_MONTHLY_LIMIT_YEN'),
    'daily_call_limit' => env('FOUNDATION_AI_DAILY_CALL_LIMIT'),
    'yen_per_usd' => 150,
    // ドル／100万トークン。会社の種類（driver）とモデルの名前で引く
    'prices' => [
        'anthropic' => [
            'claude-xxx' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 3.75, 'cache_read' => 0.3],
        ],
    ],
],
```

3. 表を作る。有効なときはパッケージのマイグレーションが自動で読まれる：

```bash
php artisan migrate
```

表は `foundation_ai_usages`・`foundation_ai_totals`・`foundation_ai_notices`。無効のままでも `php artisan vendor:publish --tag=foundation-ai-migrations` で写せる。

4. 使用量の行を消す処理を schedule に入れる（自動では消えない）：

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Ai\AiUsage::class]])->daily();
```

`--model` なしの `model:prune` は `app/Models` しか見ない。`foundation.ai.prune_days`（既定 90）日たった行が消える。**日ごとの合計の表は消さない**（上限の判断に使う。1日1行）。

## 上限の決まり

| 設定 | 意味 |
|---|---|
| `daily_limit_yen` | アプリ全体の1日の金額（円。`1000.5` のような小数も可） |
| `monthly_limit_yen` | アプリ全体の1か月の金額（円） |
| `daily_call_limit` | 1日の呼び出しの回数（小数は切り捨てる） |

- **空（`null` や `.env` の空の値）は上限なし。`0` はすべて止める。** 数でない値・負の値は「数えられない」とみなして `when_uncountable` に従う。
- 日・月の区切りは**アプリのタイムゾーン**（`config('app.timezone')`）。呼ぶたびに `now()` で決めるので、長く動くキューのワーカーでも日・月が変わると数え直す。
- 送る直前に今日・今月の合計を見て、**どれかの上限以上なら AI の会社へ送らず**、`AiLimitReached` を投げる。通ったら今日の回数を1つ足す。送った後に、その呼び出しの金額を今日の合計へ足す。
- **合計を見てから送るので、最後の1回の分と、同時に走る呼び出しの分だけ上限を超えうる。**
- 数えるのは、AI の会社へ**1回送るごと**：エージェントの段（道具を使う会話では1回の質問で何回も送る）と、分類（`Classification::of()`）。失敗した段・分類も回数に入る（失敗を繰り返す不具合を回数の上限で止めるため）。
- 1か月の上限は、その月の日ごとの合計を足して見る。

### 数えられないとき（`when_uncountable`）

使った額を数えられないときの扱いを決める。**既定は `block`**（費用を守る部品なので、設定し忘れたら止める側に倒す）。

- `block`：`AiLimitReached`（理由 `uncountable`）を投げて送らない
- `allow`：`report()` し、**数えられない上限だけを外して**、残った上限で確かめる（たとえば単価の表がなくても、回数の上限は効く。日の金額の上限の設定が読めなくても、月の金額と回数の上限は効く）。合計を読めないときは、どの上限も確かめられないので送る

数えられないのは次のとき：

- 金額の上限があるのに、単価の表（`prices`）に使える行がない、または `yen_per_usd` が 0 以下や数でない
- 合計の表を読めない（DB が落ちている・表がない）
- 上限の設定が数として読めない
- 回数の上限（`daily_call_limit`）があるのに、呼び出しの回数を足せない（DB が落ちているなど）

回数の上限がなければ、回数を足せなくても止めない（`report()` して送る。効かなくなる上限がないため）。上限を1つも決めていないときは、合計を読めないこと自体でも止めない。DB が落ちている間も AI を呼びたいアプリは `allow` にする。

金額の上限が日も月もなければ、単価の表が空でも止めない（回数の上限だけを決めたアプリは単価が要らない）。

### 知らせ

上限で止めたとき（`uncountable` を含む）、運営へ知らせる。**`foundation.monitoring.enabled` が真のときだけ届く**（知らせの部品 `AlertSender` を使う。送り先は `monitoring.alerts.channels`。[monitoring.md](monitoring.md)）。偽のときも、上限で止めることは効く。

- 知らせの鍵は `foundation.ai.limit:<理由>:<Y-m-d>`（月の上限は `:<Y-m>`）。理由は `day_cost`・`month_cost`・`day_calls`・`uncountable`。**同じ日（月の上限は同じ月）・同じ理由では1回だけ**届く。
- 「送ったか」の印は表 `foundation_ai_notices` に、1回の書き込みで立てる。立てられたときだけ送る。
- 「数えられない」の知らせの本文には、数えられない理由の文に加えて、原因の例外のクラス名と、その文の先頭300文字が入る。DB の誤りの文には接続先などが入りうるので、知らせの送り先は運営だけが読める所にする。
- 知らせを送れなくても、止め方は変わらない（`report()` して続ける）。

## 単価の表

`foundation.ai.prices` に、会社の種類とモデルごとの単価（ドル／100万トークン）を書く。**既定値は入れていない**（各社の値が変わると古くなるため）。アプリで書き、**確かめた日をコメントに残す**。

```php
'prices' => [
    // 2026-11-16 に各社の料金表で確かめた
    'anthropic' => ['claude-xxx' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 3.75, 'cache_read' => 0.3]],
],
```

- **引き方：** 会社の種類（`anthropic`・`openai` など。アプリが設定に付けた名前ではない）とモデルで引く。モデルは**頼んだ名前で引き、なければ答えたモデルの名前で引く**（別名と日付付きの名前の違い）。
- 4つの単価（`input`・`output`・`cache_write`・`cache_read`）が**すべて0以上の数**の行だけが正しい行。1つでも欠けた・数でない行は「単価なし」として扱い、いちばん高い単価の計算にも入れない。
- **単価のないモデルを0円にしない。** 行には「単価なし」の印（`priced` = 偽）を付け、実際の原価は空にし、上限に数える額は**表のいちばん高い単価**で見積もる（高く見積もる側に倒す）。
- **表が空のとき**は、どのモデルも数えられない。金額の上限があれば `when_uncountable` に従う（既定では、単価を書くまで AI を呼べない）。
- 入力のトークンにはキャッシュの読み・書きが含まれる。原価は、入力からキャッシュの分を引いた分を入力の単価で、キャッシュの分をキャッシュの単価で数える（二重に数えない）。

## 記録の表の読み方

| 表 | 中身 |
|---|---|
| `foundation_ai_usages` | 呼び出しごとの行（モデル `Miraqceed\Foundation\Ai\AiUsage`） |
| `foundation_ai_totals` | 日ごとの合計の1行（日付・上限に数えた額・呼び出しの回数）。上限の判断はこの表だけを読む |
| `foundation_ai_notices` | 知らせを送った印 |

`foundation_ai_usages` の主な列：

- `kind`（`text` か `classification`）・`status`（`completed` か `failed`）
- `provider_name`（アプリが付けた名前）・`provider_driver`（種類）・`requested_model`・`responded_model`
- `input_tokens`・`output_tokens`、`cache_read_tokens`・`cache_write_tokens`・`reasoning_tokens`（値がないのは `null`。0と分けて持つ）
- `cost_micro_yen`：**実際の原価**。単位はマイクロ円（1円 = 1,000,000）の整数。単価がなければ空
- `counted_micro_yen`：**上限に数えた額**。単価がなければ見積もり。合計はこちらを足す。後から単価の表を変えても過去の合計は変わらない
- `priced`：単価があったか
- `invocation_id`・`step_number`・`agent`（エージェントのクラス名）・`created_at`
- `error`：失敗の行だけ。例外のクラス名のみ（文には秘密が入りうるので残さない）

「何にいくら使ったか」は `cost_micro_yen` で読み、「上限にいくら数えたか」は `counted_micro_yen` で読む。失敗の行は金額が空で、合計には足さない。

## 例外の扱い

上限で止めると `Miraqceed\Foundation\Ai\AiLimitReached` を投げる。`reason` に `day_cost`・`month_cost`・`day_calls`・`uncountable` が入る。捕まえて画面に出す：

```php
use Miraqceed\Foundation\Ai\AiLimitReached;

try {
    $answer = $agent->prompt($text);
} catch (AiLimitReached $e) {
    return back()->withErrors(['ai' => '今は AI を使えません。']);
}
```

- この例外は SDK の `FailoverableException` を**実装しない**。実装すると、SDK が次の会社へ切り替えて送ってしまう。上限で止めたら切り替え先へも送らない。
- キューのジョブの中で止まると、ジョブの `tries` の回数だけやり直す。上限の間は毎回止まるので、`tries` を小さくするか、ジョブの中で `AiLimitReached` を捕まえて `fail()` や `release()` を選ぶ。

## してはいけないこと

- **AI は Laravel AI SDK で呼ぶ。各社の SDK や HTTP で直に呼ばない。** 直に呼んだ分は数えられず、上限もかからない。
- 上限を外すために、`foundation_ai_totals` の行を書き換えない。

## 限界

- **文の生成（エージェント・答えの形を決めた出力・ストリーミング）と分類だけを数える。画像・音声・文字起こし・埋め込み・並べ替えは数えず、止めない。** 上限に達しているときにこれらも止めたいなら、アプリで知らせの形を見て決める。
- **最後まで読まなかったストリーミングの金額は漏れる。** 完了の知らせも失敗の知らせも出ないため。回数には送る前に入るので、回数の上限は効く。
- **同時に走る呼び出しと最後の1回の分だけ、上限を超えうる**（上の「上限の決まり」）。
- **記録は、アプリのトランザクションが巻き戻っても残る**よう、既定の接続の設定を写した別の接続 `foundation_ai` で書く（払った後の額が消えると上限をすり抜けるため）。見張りの知らせ（[monitoring.md](monitoring.md)）は既定の接続に savepoint で書くので、書き方が違う。合計も、この接続の書き込みの側から読む（読み書きを分けたアプリで、遅れる複製から読んで使った額を見落とさない）。
  - **AI を呼ぶプロセスごとに、DB の接続が1本増える。** キューのワーカー・Octane・Horizon のように接続を持ち続けるプロセスでは、DB の接続の上限（PostgreSQL の `max_connections`、PgBouncer の枠など）に近いアプリで効いてくる。足りなければ、下のとおり `database.connections.foundation_ai` を定めて向き先を選ぶ。
  - **アプリが `database.connections.foundation_ai` を自分で定めていれば、写さずにそれを使う。** テナントごとに既定の接続の DB を替えるアプリ（記録を1か所に集めたい）や、写しでは向き先が合わないアプリは、ここで接続を定める。表はその接続の DB に要る（パッケージのマイグレーションは既定の接続に作る。別の DB に向けるなら、マイグレーションを写して `$connection` を足す）。
  - ただし次の2つでは、定めていても既定の接続で書く。そのときは savepoint で包み、失敗した文がアプリのトランザクションを壊さないようにする（PostgreSQL は失敗した文でトランザクションごと使えなくなる）：
    - **既定の接続が SQLite のとき。** 巻き戻すと、記録と知らせの印が消えうる。トランザクションの中で呼ぶと、やり直すたびに知らせが届きうる。
    - **テストの実行中。** `RefreshDatabase` が巻き戻すのは既定の接続だけなので、別の接続の記録が試しをまたいで残るのを避ける。
- **印を取った後に知らせを書けなかったとき、また、アプリのトランザクションの巻き戻しで知らせと送り先の行ごと消えたときは、その日（月）の知らせは届かない。** 印は残るので、送り直しにも拾われない。たとえば、トランザクションの中で上限で止めた直後にそのトランザクションが巻き戻り、その場の送り先への送信も失敗すると、印は残るのに知らせと送り先の行が消える。上限で止めることは効いている。
- **持ち主が上限の設定を外したり、合計の表を書き換えたりすることは止められない。**
- **SDK の版は `composer.json` の `conflict`（`<1.4 || >=2.0`）の範囲だけ。** 知らせの形は SDK の版で変わりうるので、読んで確かめた版だけを許す。
- **SDK のないアプリで `foundation.ai.enabled` を真にしても、何もしない**（聞き手を登録せず、例外も出さない）。表のマイグレーションは読み込まれる。
- 1分ごとの回数の上限は持たない。
