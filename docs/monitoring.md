# 定期の処理の見張りと知らせ

名前空間 `Miraqceed\Foundation\Monitoring`。**既定は無効**。Redis・常駐のプロセスは要らない。

## いつ使うか

`schedule:run` が止まった・処理が一度も成功していない・古いまま、を運営が気付けるようにする。**見張るのは「最後に成功した時刻」**。外の死活監視と組み合わせると、見張り自身が止まったことも分かる（`heartbeat_url`）。

## 最短の手順

1. 有効にして、マイグレーションを流す：

```php
// config/foundation.php
'monitoring' => ['enabled' => true],
```

```bash
php artisan migrate   # 有効なときはパッケージのマイグレーションが自動で読まれる
```

表は `foundation_task_runs`・`foundation_alerts`・`foundation_alert_deliveries`。無効のままでも `php artisan vendor:publish --tag=foundation-migrations` で写せる。

2. schedule の処理に `->watched('名前', 許す古さ（分）)` を付ける。**開始・成功・失敗が記録される。**

```php
// routes/console.php
Schedule::command('reports:daily')->dailyAt('03:00')->watched('daily-report', 1560); // 26時間
```

**`->environments('production')` などで絞った処理は、その環境の外では見張らない**（ステージングで「一度も成功していません」と知らせない）。**`->when()`・`->skip()` の条件は見張る時点で評価できないので、条件で動かない処理も見張る**：条件で止める処理には `->watched()` を付けないか、許す古さを条件で止まる長さより長くする。

3. `foundation:watch` は**自動で schedule に入る**（有効なときだけ。`monitoring.watch_cron`、既定 `0 * * * *`＝毎時。`withoutOverlapping(10)`）。入れ忘れても動く。
4. **`schedule:run` を毎分回す cron は、アプリ側で用意する**（`* * * * * php artisan schedule:run`）。

### `withoutOverlapping` とキャッシュのロック

キャッシュが `database` のとき、`withoutOverlapping` は **`cache_locks` の表**を使う。Laravel の `php artisan make:cache-table`（新しい Laravel では最初のマイグレーションに入っている）で作られていないと、`foundation:watch` が動かない。

## schedule の外の処理（バックアップのスクリプトなど）

処理の終わりで記録し、`monitoring.external` に見張りを書く：

```bash
# 失敗したときは記録したうえで、台本の終了コードを残す（cron・呼び出し元が失敗に気付けるように）
if ./backup.sh; then
    php artisan foundation:record backup
else
    status=$?
    php artisan foundation:record backup --failed --detail="dump 失敗"
    exit "$status"
fi
```

`./backup.sh && php artisan foundation:record backup || php artisan foundation:record backup --failed` と1行で書かない。失敗の記録が成功すると、行全体の終了コードがいつも 0 になり、失敗が cron から見えなくなる。

```php
'monitoring' => [
    'external' => [
        'backup' => ['max_age_minutes' => 1560, 'label' => '日次のバックアップ'],
    ],
],
```

**`external` の既定は空**（アプリが書いた分だけ見張る）。書いたのに `foundation:record` を呼ばないと、26時間後に「一度も成功していません」の知らせが出る。`max_age_minutes` の既定は 1440、`label` の既定は名前。

## 見張りの判定

`foundation:watch` は、見張る名前ごとに：最後の成功が許す古さより前なら問題。一度も成功していなければ、**初めて見てから**許す古さがたつまでは問題にしない。問題があれば運営へ知らせ（鍵は止まっている処理の名前の組ごと。下の「間引きの鍵」）、終了コード 1。なければ `heartbeat_url`（あれば）へ GET して 0。送れなくても失敗にしない。

## 運営への知らせ

```php
'alerts' => [
    'channels' => ['log'],          // log・mail・webhook・chatwork・slack
    'throttle_minutes' => 60,       // 同じ鍵の知らせを作らない間（10より大きいときは5分短く見る）
    'max_attempts' => 10,           // 送り先ごとの試す回数の上限
    'prune_days' => 90,             // 送れたか諦めてこの日数がたった知らせを model:prune で消す
    'log' => ['channel' => null, 'level' => 'error'], // null はアプリの既定のチャネル
    'mail' => ['to' => 'ops@example.com'],
    'webhook' => ['url' => '…', 'secret' => env('FOUNDATION_WEBHOOK_SECRET')],
    'chatwork' => ['token' => '…', 'room_id' => '…'],
    'slack' => ['webhook_url' => '…'],
],
```

- **送り先は用意した5つ（`log`・`mail`・`webhook`・`chatwork`・`slack`）で固定。** 第1段ではアプリが送り先を足す口はない（`Channel` は内部の interface で、0.x のうちに変わりうる）。
- **MiraqStudio の顧客のアプリは `log` の送り先を使い、`log.channel` を `stderr` にする**（Runtime Agent がコンテナの標準エラーを読んで運営に届ける）：

```php
'alerts' => [
    'channels' => ['log'],
    'log' => ['channel' => 'stderr'],
],
```

- `log.channel` が `null`（既定）なら、アプリの既定のチャネル（`LOG_CHANNEL`）に書く。`log.level`（既定 `error`）は書く重さ。**チャネルの `level`（`LOG_LEVEL`）より低い重さにすると、Monolog が行を捨てるのに、知らせは送れた扱いになる**（送り直さない）。`LOG_LEVEL` を `error` より高くしているアプリは `log.level` も上げる。
- 自分のコードから知らせるとき：`app(AlertSender::class)->send('鍵', '件名', '本文')`。**投げない**（送り先の失敗も書き込みの失敗も飲む。DB への書き込みは savepoint で包み、PostgreSQL のトランザクションを中断させない）。戻りは `Alert`、間引かれたら `null`。
- 知らせは先に DB へ書いてから送る。送れなかった行は `foundation:watch`（毎回）と `php artisan foundation:alerts:retry --limit=20` で送り直す。`max_attempts` に達したら諦め、`foundation.alert.abandoned` を `Log::error` に残して `report()` する。
- **間引きの鍵**：`foundation:watch` の鍵は `foundation.watch:` ＋ `sha1(止まっている処理の名前を並べ替えて ',' でつないだもの)`（例：`daily-report` だけなら `foundation.watch:7e5b43f6d13236348a716779f9ef19b9939f886d`）。**新しく止まった処理は、間引きの間でもすぐ知らせる**（組が変わるので鍵が変わる）。同じ組のままなら間引く。
- **間引きの幅**：同じ鍵の知らせを作っていれば作らない間は、`throttle_minutes` が10より大きいときは `throttle_minutes - 5` 分（既定の60なら55分）、10以下ならそのまま。毎時の見張りと60分の間引きでは、送り直し（最大20行）で前の回の作成が数分遅れると、次の回が間引かれて1時間おきに抜けるため。止まったままなら、毎時の見張りのたびに知らせる。
- **知らせの表は自動では消えない。** `Alert` は `MassPrunable` で、すべての送り先が送れたか諦めて `prune_days`（既定 90）日たった知らせを、送り先の行ごと（外部キーの cascade）消す。まだ送り直す送り先が残る知らせは消さない。**`model:prune` を schedule に入れるのはアプリ**（`--model` なしの `model:prune` は `app/Models` しか見ないので、パッケージのモデルを `--model` で指す。アプリのモデルも消すなら、それも並べる）：

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [
    \Miraqceed\Foundation\Monitoring\Alerts\Alert::class,
    // \App\Models\…::class,
]])->daily();
```

- **外のトランザクションの中で `send()` を呼ぶと**、その外側が rollback されたとき知らせの行ごと消える。トランザクションの外で呼ぶ。
- メールの `timeout` は mailer の設定に任せる。

## ログの1行の形（v1 の契約）

`log` の送り先は、`log.channel` のチャネルに `log.level` の重さで次の1行を書く。**文は `foundation.alert ` ＋ 1行の JSON**（改行は `\n` に直る。日本語・スラッシュはそのまま。不正な UTF-8 は U+FFFD に置き換える）：

```
foundation.alert {"v":1,"id":123,"key":"foundation.watch:7e5b43f6d13236348a716779f9ef19b9939f886d","subject":"定期の処理が止まっています","body":"定期の処理が止まっているか、動いていません。\n\n・daily-report：一度も成功していません","app":"MyApp","at":"2026-11-15T03:00:00+09:00"}
```

- キー：`v`（形の版。いまは 1）・`id`（知らせの id。同じ知らせの送り直しでは変わらない）・`key`・`subject`・`body`（4000 文字まで）・`app`（`config('app.name')`）・`at`（知らせを作った時刻、ISO 8601）。
- **`id` で重複を除く**：Webhook は送り直しのたびに時刻と署名を新しくするので、受け取る側が処理を済ませた後に時間切れになると、同じ知らせが2回届く。同じ `id` は同じ知らせ（`key` が同じでも `id` が違えば新しい知らせ）。読む側はキーを足されても読めるように、知らないキーは無視する（v1 のうちはキーを足すだけで、消したり意味を変えたりしない）。
- パッケージは Monolog の context と extra を**いつも空で渡す**（文の中の `{…}` も置き換わらない）。

### 読み方（読む側はこのとおりに読む）

Monolog の整形で、文の前に飾り（`[日時] 環境.重さ: `）が付き、後ろに空の context と extra が付くことがある。読む側（Runtime Agent など）は：

1. 行の中で最初の `foundation.alert `（後ろの空白まで）を探す（**行頭では探さない**）。
2. その後ろから**行末まで**を取る。
3. 末尾の空白を落とし、末尾が ` [] []`（Monolog の空の context と extra）ならそれも落として、もう一度末尾の空白を落とす。
4. 残りを JSON として解析する。

`{` から最初の `}` までを取る読み方はしない（本文に `}` を含むと途中で切れる）。JSON は `}` で終わるので、末尾の ` [] []` が JSON の一部になることはない。

| 整形 | 実際の行の例 |
|---|---|
| Laravel の既定（空の context・extra を出さない） | `[2026-11-15 03:00:00] production.ERROR: foundation.alert {"v":1,…,"at":"…"}  `（末尾に空白が2つ） |
| Monolog の素の `LineFormatter` | `[2026-11-15 03:00:00] app.ERROR: foundation.alert {"v":1,…,"at":"…"} [] []` |

本文に `}` を含む例（`body` が `閉じ括弧 } と [] [] を含む`）：

```
[2026-11-15 03:00:00] production.ERROR: foundation.alert {"v":1,"id":124,"key":"k","subject":"件名","body":"閉じ括弧 } と [] [] を含む","app":"MyApp","at":"2026-11-15T03:00:00+09:00"} [] []
```

手順2〜3で ` [] []` だけが落ち、`body` は元のまま読める。試し `tests/Feature/Monitoring/LogChannelTest.php` が、本物の Monolog の2つの整形でこの読み方を確かめる。

**前提：アプリがそのチャネルに context・extra を足していないこと。** `Log::shareContext()`・チャネルの `withContext()`・`Context::add()`（Laravel が extra に足す）を使うと、末尾が ` [] []` でなくなり、この読み方では読めない。知らせを書くチャネル（`log.channel`）には足さない。

## Webhook の署名

`webhook` の送り先は、上の JSON をそのまま本文に、`Content-Type: application/json` で POST する。**`secret` が空なら送らない**（署名のない知らせは偽物と見分けられない）。ヘッダー：

- `X-Foundation-Timestamp`：送った時刻（UNIX 秒）
- `X-Foundation-Signature`：`v1=` ＋ `hex(hmac_sha256(secret, timestamp + "." + body))`

**受け取る側の確かめ**：①`abs(now - timestamp) <= 300`（秒。許容幅は 300 秒）、②受け取った**生の本文**（再エンコードしない）で署名を計算し、`hash_equals` 相当の定数時間の比較で `X-Foundation-Signature` と比べる。両方通ったときだけ受け付ける。③処理を済ませた知らせの `id` を覚えておき、同じ `id` がまた届いたら処理せずに 2xx を返す（送り直しの重複）。

例（試し `tests/Unit/Monitoring/WebhookSignatureTest.php` が同じ値を確かめる）：

| 項目 | 値 |
|---|---|
| secret | `secret` |
| timestamp | `1700000000` |
| body | `{"v":1}` |
| 署名の元の文字列 | `1700000000.{"v":1}` |
| X-Foundation-Signature | `v1=8a547bfd2adcd07f78c08448d60b727dd145789fe26971db4ead66af709bb87e` |

```php
$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
$fresh = abs(time() - (int) $timestamp) <= 300;
$ok = $fresh && hash_equals($expected, $signatureHeader);
```

## してはいけないこと

- `monitoring.enabled` を有効にせずに `watched()` だけ書いて「見張っている」と思わない（無効のあいだは印を付けるだけで、記録も見張りもしない）。
- `->watched()` の名前を重複させない（同じ名前は1つの記録を共有する）。
- 秘密（`webhook.secret`・各トークン）をコードに書かない。`.env` から `env()` で設定に読む。
- 外の処理を `external` に書いて `foundation:record` を呼び忘れない。
- `model:prune` を `--model` なしで schedule に入れて、知らせの表も消えると思わない（パッケージのモデルは `--model` で指す）。

## 限界

- 見張るのは「最後に成功した時刻」だけ。処理の中身の正しさは見ない。
- `foundation:watch` 自身が止まる（`schedule:run` の cron が止まる）と、パッケージは気付けない。`heartbeat_url` の外の死活監視で補う。
