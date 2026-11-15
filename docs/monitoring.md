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

3. `foundation:watch` は**自動で schedule に入る**（有効なときだけ。`monitoring.watch_cron`、既定 `0 * * * *`＝毎時。`withoutOverlapping(10)`）。入れ忘れても動く。
4. **`schedule:run` を毎分回す cron は、アプリ側で用意する**（`* * * * * php artisan schedule:run`）。

### `withoutOverlapping` とキャッシュのロック

キャッシュが `database` のとき、`withoutOverlapping` は **`cache_locks` の表**を使う。Laravel の `php artisan make:cache-table`（新しい Laravel では最初のマイグレーションに入っている）で作られていないと、`foundation:watch` が動かない。

## schedule の外の処理（バックアップのスクリプトなど）

処理の終わりで記録し、`monitoring.external` に見張りを書く：

```bash
./backup.sh && php artisan foundation:record backup || php artisan foundation:record backup --failed --detail="dump 失敗"
```

```php
'monitoring' => [
    'external' => [
        'backup' => ['max_age_minutes' => 1560, 'label' => '日次のバックアップ'],
    ],
],
```

**`external` の既定は空**（アプリが書いた分だけ見張る）。書いたのに `foundation:record` を呼ばないと、26時間後に「一度も成功していません」の知らせが出る。`max_age_minutes` の既定は 1440、`label` の既定は名前。

## 見張りの判定

`foundation:watch` は、見張る名前ごとに：最後の成功が許す古さより前なら問題。一度も成功していなければ、**初めて見てから**許す古さがたつまでは問題にしない。問題があれば運営へ知らせ（鍵 `foundation.watch`）、終了コード 1。なければ `heartbeat_url`（あれば）へ GET して 0。送れなくても失敗にしない。

## 運営への知らせ

```php
'alerts' => [
    'channels' => ['log'],          // log・mail・webhook・chatwork・slack
    'throttle_minutes' => 60,       // 同じ鍵の知らせを作らない間
    'max_attempts' => 10,           // 送り先ごとの試す回数の上限
    'mail' => ['to' => 'ops@example.com'],
    'webhook' => ['url' => '…', 'secret' => env('FOUNDATION_WEBHOOK_SECRET')],
    'chatwork' => ['token' => '…', 'room_id' => '…'],
    'slack' => ['webhook_url' => '…'],
],
```

- **MiraqStudio の顧客のアプリは `log` の送り先を使う**（Runtime Agent がログを読んで運営に届ける）。
- 自分のコードから知らせるとき：`app(AlertSender::class)->send('鍵', '件名', '本文')`。**投げない**（送り先の失敗も書き込みの失敗も飲む。DB への書き込みは savepoint で包み、PostgreSQL のトランザクションを中断させない）。戻りは `Alert`、間引かれたら `null`。
- 知らせは先に DB へ書いてから送る。送れなかった行は `foundation:watch`（毎回）と `php artisan foundation:alerts:retry --limit=20` で送り直す。`max_attempts` に達したら諦め、`foundation.alert.abandoned` を `Log::error` に残して `report()` する。
- **間引きの鍵は1つ。** 60分の間は、同じ鍵（`foundation.watch`）の知らせは作らない。そのため、別のタスクが後から止まっても、間引きの間は**別の知らせにならない**（次の知らせに新しい停止も入る）。
- **外のトランザクションの中で `send()` を呼ぶと**、その外側が rollback されたとき知らせの行ごと消える。トランザクションの外で呼ぶ。
- メールの `timeout` は mailer の設定に任せる。

## ログの1行の形

`log` の送り先は、`Log::warning` で次の1行を書く。**行は `foundation.alert ` ＋ 1行の JSON**（改行は `\n` に直る。日本語・スラッシュはそのまま）：

```
foundation.alert {"v":1,"key":"foundation.watch","subject":"定期の処理が止まっています","body":"定期の処理が止まっているか、動いていません。\n\n・daily-report：一度も成功していません","app":"MyApp","at":"2026-11-15T03:00:00+09:00"}
```

- キー：`v`（形の版。いまは 1）・`key`・`subject`・`body`（4000 文字まで）・`app`（`config('app.name')`）・`at`（知らせを作った時刻、ISO 8601）。
- **Monolog の飾りが前に付く。** Laravel の既定の整形だと実際の行は `[2026-11-15 03:00:00] production.WARNING: foundation.alert {…} [] []` になる。読む側（Runtime Agent など）は**行頭ではなく `foundation.alert ` の位置で探し**、その後ろの `{` から対応する `}` までを JSON として読む（行末の `[] []` は飾り）。

## Webhook の署名

`webhook` の送り先は、上の JSON をそのまま本文に、`Content-Type: application/json` で POST する。**`secret` が空なら送らない**（署名のない知らせは偽物と見分けられない）。ヘッダー：

- `X-Foundation-Timestamp`：送った時刻（UNIX 秒）
- `X-Foundation-Signature`：`v1=` ＋ `hex(hmac_sha256(secret, timestamp + "." + body))`

**受け取る側の確かめ**：①`abs(now - timestamp) <= 300`（秒。許容幅は 300 秒）、②受け取った**生の本文**（再エンコードしない）で署名を計算し、`hash_equals` 相当の定数時間の比較で `X-Foundation-Signature` と比べる。両方通ったときだけ受け付ける。

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

## 限界

- 見張るのは「最後に成功した時刻」だけ。処理の中身の正しさは見ない。
- `foundation:watch` 自身が止まる（`schedule:run` の cron が止まる）と、パッケージは気付けない。`heartbeat_url` の外の死活監視で補う。
