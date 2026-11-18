# 共用サーバーで cron からキューを回す

名前空間 `Miraqceed\Foundation\Queue`。**入れただけでは変わらない。** worker は、アプリが `routes/console.php` で `CronWorker::schedule()` を呼んだときだけ登録する。見張り（キューの溜まり・失敗のジョブ・DB の大きさ）は `foundation.monitoring.enabled` のときだけ働く（`docs/monitoring.md`）。

## いつ使うか

常駐のプロセス（Supervisor・Horizon）を置けない共用サーバーで、`database` のキューを使うとき。毎分の cron（`schedule:run`）から、短い時間だけ動く `queue:work` を回す。

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

- `php` は、Web と同じ版の CLI の絶対の道で書く（共用サーバーでは、Web の PHP の切り替えが CLI に効かないことがある）
- `CronWorker` は worker を裏で動かす（`runInBackground()`）。PHP の `exec` 系の関数が使えることが要る

## 使い方

```php
// routes/console.php
use Miraqceed\Foundation\Queue\CronWorker;

// 登録・パスワードの再設定などのメールを先に。重い仕事は別の worker に（メールが重い仕事の後ろで待たない）
CronWorker::schedule(queues: ['mail', 'default'], maxTime: 20, timeout: 30);
CronWorker::schedule(queues: ['heavy'], maxTime: 40, timeout: 75);
```

```php
// メールのジョブを先のキューへ
Mail::to($user)->queue((new WelcomeMail($user))->onQueue('mail'));
```

登録するもの：

```
queue:work <接続> --queue=<並び> --stop-when-empty --max-time=<maxTime> --timeout=<timeout> --tries=<tries> --sleep=1
```

- 毎分。`withoutOverlapping(ロックの分)`。`runInBackground()`（1つの遅い worker が、ほかの毎分の処理を遅らせない）
- `->watched('queue-worker:<並び>', ロックの分 + 5)`：見張り（`docs/monitoring.md`）が「最後に成功した時刻」を見る。worker が回らなくなると知らせる
- 引数：`queues`（取る順。前ほど先に取る。英数字と `. _ : -`）、`maxTime`（1回の予算・秒、既定 40）、`timeout`（ジョブ1つの上限・秒、既定 30）、`tries`（既定 3）、`connection`（既定 `queue.default`）
- 返り値は Laravel の `Event`。`->environments('production')` などを足せる

### ロックの分

`--max-time` は**ジョブとジョブの間でしか見ない**。処理中のジョブは止めないので、1回の worker は「予算＋ジョブ1つ」まで動く。重なり止めのロックの分は `ceil((maxTime + timeout + 30) / 60)` にする（重ならない）。

**落ちたとき（kill・メモリ不足）は、ロックの分だけ、そのキューが止まる。** ロックはキャッシュ（`database` なら `cache_locks` の表）に、期限まで残る。

### timeout と retry_after

`database` のキューは、取ってから `retry_after` 秒たったジョブを、**処理中でも**次の worker が取り直す。同じジョブが2回動く。

- `timeout + 10 <= retry_after` にする（10 秒は余白：timeout で止められた後、失敗の記録と解放に時間が掛かる）。満たさない登録は、本番でない環境では `LogicException`、本番では `foundation:deploy-check` の `queue_timeouts` が `FAIL` にする
- ジョブのクラスに `$timeout` を書くと、`--timeout` より先に使われる。そこにも同じ決まりを守る
- `retry_after` は `config/queue.php` の接続ごと（`DB_QUEUE_RETRY_AFTER`、Laravel の既定は 90）

### pcntl がないとき

worker の `--timeout` は、PHP の pcntl の拡張があるときだけ効く（`SIGALRM` でジョブを止める）。共用サーバーの CLI にあるとは限らない（`php -m` で確かめる。`foundation:deploy-check` の `queue_timeouts` が `later` にする）。

ないときは、止まったジョブを誰も止めない。

- ジョブの中の外への通信（HTTP・メール）に、短い timeout を付ける
- `retry_after` を、予算＋一番長いジョブより長くする

## 間違えやすいこと

- **本番で `QUEUE_CONNECTION=sync` にしない。** `->delay()` が無視され、ジョブが要求の中で動き、失敗が利用者に返る（`foundation:deploy-check` の `queue_worker` が `later`）。`database` にしたのに worker を schedule に入れないと、ジョブが溜まるだけ（`FAIL`）
- **本番で `cache:clear` を流さない。** `database` のキャッシュでは、回数の制限・ログインのロックの数え・アプリが置いた続きの位置などが消える（重なり止めのロックの表 `cache_locks` は消えない）
- **毎分の処理に `->runInBackground()` だけを付けて、`withoutOverlapping()` を付けない書き方をしない。** 前の回が終わらないうちに次の回が始まり、重なって増える
- 失敗のジョブは、原因を直してから `php artisan queue:retry` で流し直す。冪等でないジョブ（決済・メール）は、流し直す前に、済んでいないことを確かめる

## キューの溜まりを読む（画面・状態の表示）

```php
use Miraqceed\Foundation\Monitoring\QueueCheck;

foreach (app(QueueCheck::class)->stalled() as $state) {
    echo $state->message(), PHP_EOL; // 例「キュー database:mail：12分待っているジョブがあります（許す待ち 10分。…）」
}
```

- 読むだけ（知らせない・書かない）。`foundation:watch` と同じ判断
- 見るキュー：`CronWorker` で登録したキュー（許す待ちは既定の10分）と、設定 `foundation.monitoring.queues`
- `states()` は全部のキュー、`stalled()` は溜まった（か読めない）キュー。`QueueState` は件数と待ちだけで、ジョブの中身・id を持たない
- 見張りが無効なら空

```php
// config/foundation.php
'monitoring' => [
    'queues' => [
        'database' => ['mail' => 5, 'heavy' => 60],   // 接続 => [キュー => 許す待ち（分）]
    ],
],
```
