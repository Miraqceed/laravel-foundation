# 消す決まりの台帳と、台帳からの消去

名前空間 `Miraqceed\Foundation\Retention`。DB の**すべての表**の消し方を1つのクラス（台帳）に書き、試しで書き忘れを止め、退会・解約したアカウントを台帳のとおりに消す。

- 台帳 `DataMap`：表ごとに、持ち主の消去で消す・行を残して書き換える・期限で消す・残す・アプリが自分で消す、のどれかを書く
- 試しの助け `Testing\DataMapAssertions`：DB にあって台帳にない表・列、schedule にない期限の消し方で落ちる
- 消去 `Erasure`：持ち主の行を握って確かめ直し、台帳のとおりに消して書き換える
- deploy-check の調べ `retention_schedule`：本番の schedule に、期限の消し方があるか

**入れただけでは変わらない。** 台帳（`foundation.retention.map`）を書かなければ、試しの助けは落ち、deploy-check は `skip`、`Erasure` は `LogicException`。

## なぜ要るか

消し忘れは「並べ忘れ」で起きる。

- 表を足したが、退会の消去の処理に足し忘れる。個人の情報が残り続ける
- 行を残す表（請求の帰属先の `users` など）に列を足したが、匿名化に足し忘れる
- 期限で消すモデル（`Prunable`）を作ったが、`model:prune` の schedule に入れ忘れる。`model:prune` は `--model` を渡さなければ `app/Models` の中しか探さないので、パッケージのモデルは入れ忘れやすい
- Laravel の表（`failed_jobs` など）を消すコマンドを入れ忘れる。どれも、入れ忘れても何も言わない
- 消す前に読んだ条件（解約して30日）が、消すまでに変わる（解約を取り消した）

台帳は消し方そのものなので、台帳とコードが食い違わない。試しは DB の作り（マイグレーションの後）と比べるので、表・列を足した PR で落ちる。

## 台帳を書く

```php
// app/Retention/AppDataMap.php
use Miraqceed\Foundation\Retention\Anonymize;
use Miraqceed\Foundation\Retention\DataMap;
use Miraqceed\Foundation\Retention\MapBuilder;

final class AppDataMap implements DataMap
{
    public function define(MapBuilder $map): void
    {
        // 持ち主（退会・解約で消す単位）。消すときに握る行の表と鍵
        $map->owner('user', table: 'users');

        // 行を残し、列を書き換える表：すべての列を erase（書き込む値）か keep（残す）に分け、残す理由を書く
        $map->table('users')->anonymizedWith('user', key: 'id', erase: [
            'name' => '退会したユーザー',
            'email' => Anonymize::unique('deleted-{id}@anonymized.invalid'),
            'password' => '',
            'remember_token' => null,
            'two_factor_secret' => null,
        ], keep: ['id', 'plan', 'created_at', 'updated_at', 'deleted_at'], reason: '請求と集計の帰属先');

        // 持ち主の消去で消す
        $map->table('posts')->erasedWith('user', column: 'user_id');
        $map->table('post_images')->erasedWith('user', via: ['posts', 'post_id', 'user_id']);
        $map->table('notifications')->erasedWith('user', morph: 'notifiable');

        // 期限で消す（Prunable のモデルか、コマンド）。持ち主の消去と一緒に書ける
        $map->table('page_views')->prunedBy(\App\Models\PageView::class)->erasedWith('user', column: 'user_id');

        // 消さない（理由を書く）
        $map->table('invoices')->kept('適格請求書の写し。法令で7年残す');

        // アプリが自分で消す（ストレージのファイルも消すなど）。どこで消すかを書く
        $map->table('uploads')->handledByApp('UploadStore::purgeUser（ストレージのファイルも消す）');

        // Laravel の表（下の節）
        $map->table('sessions')->erasedWith('user', column: 'user_id');
        $map->table('password_reset_tokens')->prunedByCommand('auth:clear-resets');
        $map->table('failed_jobs')->prunedByCommand('queue:prune-failed');
        $map->table('job_batches')->prunedByCommand('queue:prune-batches');
        $map->table('jobs')->handledByApp('queue:work が処理すると消える');
        $map->table('cache')->prunedByCommand('foundation:cache-prune');
        $map->table('cache_locks')->handledByApp('ロックは解くと消える');
    }
}
```

```php
// config/foundation.php
'retention' => [
    'map' => \App\Retention\AppDataMap::class,
],
```

- **すべての表を書く。** 書いていない表があると、試しが落ちる（`migrations` は数えない）
- **行を残す表は、すべての列を分ける。** 列を足すと、試しが落ちる。`keep` に置く列は、個人の情報でないもの（id・プラン・時刻）にする
- 1つの表に、期限の消し方と持ち主の消去の両方を書ける。`kept()` はほかの消し方と一緒に書けない。`handledByApp()` は持ち主の消去・`kept()` と一緒に書けない
- 書く順は問わない。消す順は `Erasure` が外部キーから決める
- 書き違い（同じ列を `erase` と `keep` の両方に・書いていない持ち主・消し方のない表）は `LogicException`

### 持ち主の行の見つけ方（`erasedWith`）

どれか1つを渡す：

| 引数 | 消す行 |
|---|---|
| `column: 'user_id'` | その列が持ち主の鍵の行 |
| `via: ['posts', 'post_id', 'user_id']` | 1段の経由。`posts.user_id` が持ち主の鍵の行を指す行（`post_id` が `posts.id` を指す）。親の鍵が `id` でなければ4つ目に書く |
| `morph: 'notifiable'` | 多態の列（`notifiable_type`・`notifiable_id`）。種類が**持ち主の表のモデル**の行だけ（同じ id の、別の表の持ち主の行に触れない）。種類は `morphMap` の名前でもよい |

2段より深い経由は、途中の表を `via` で書き、その子を順に書く（外部キーがあれば、子から消す）。

### 書き換える値

`erase` には、固定の値（文字・数・`null`）か `Anonymize::unique($pattern)` を書く。

- `Anonymize::unique('deleted-{id}@anonymized.invalid')`：`{id}` を行の `id` の値に替える。一意の列（メールアドレス）に、行ごとに違う値を書く。`id` でない列を使うなら2つ目の引数に書く
- 例のドメインは `.invalid` のような、実在しないドメインにする（実在するドメインに送られない）
- 文字の連結は DB ごとにパッケージが組み立てる（MySQL・MariaDB・PostgreSQL・SQLite）

## パッケージの表

パッケージの表の分は、パッケージが台帳に足す。アプリが同じ表を書けば、アプリが勝つ。部品を有効にしておらず、表もなければ数えない。

| 表 | 消し方 |
|---|---|
| `foundation_alerts`・`foundation_alert_deliveries` | `Monitoring\Alerts\Alert` の `model:prune`（届け先の行は外部キーで一緒に消える） |
| `foundation_task_runs` | 残す（定期の処理の名前ごとの時刻。個人の情報を持たない） |
| `foundation_ai_usages` | `Ai\AiUsage` の `model:prune` |
| `foundation_ai_totals`・`foundation_ai_notices` | 残す（日ごとの合計と知らせの印。個人の情報を持たない） |
| `foundation_one_time_codes` | `OneTimeCodes\OneTimeCode` の `model:prune` |
| `foundation_notices` | `Notices\Notice` の `model:prune` |
| `foundation_email_changes` | `Accounts\EmailChange` の `model:prune`。**持ち主の消去で、その人の行（古いアドレス・新しいアドレス）もすぐに消す**（アカウントを消せば、戻すリンクで取り戻すものがない） |

二段階の認証の列（`users` に足す列）は、アプリの `users` の台帳で `erase` に書く（書き忘れると試しが落ちる）。合言葉（`foundation_one_time_codes`）の `subject` はアプリが決める文字列なので、パッケージは持ち主を知らない（期限の `model:prune` だけ）。

## Laravel の表

Laravel の表の保存の期間は、アプリが決める（パッケージは既定を持たない）。失敗したジョブの中身には個人の情報が入りうるが、調べたいときに残っている長さはアプリによる。

| 表 | 消し方 | 台帳に書く例 |
|---|---|---|
| `failed_jobs` | `queue:prune-failed`（既定 24時間より前。`--hours=` で変える） | `->prunedByCommand('queue:prune-failed')` |
| `job_batches` | `queue:prune-batches` | `->prunedByCommand('queue:prune-batches')` |
| `password_reset_tokens` | `auth:clear-resets`（期限切れのもの） | `->prunedByCommand('auth:clear-resets')` |
| `sessions` | 要求のたびのくじ（`session.lottery`、既定 2/100）で期限切れを消す。schedule は要らない。`ip_address` の列には、ログインしていない訪問者の IP も入る（入れないなら `foundation.privacy.sessions_without_ip`。`docs/privacy.md`） | `->erasedWith('user', column: 'user_id')`（退会でログインを終える） |
| `cache` | 期限切れの行は消えない。`foundation:cache-prune` | `->prunedByCommand('foundation:cache-prune')` |
| `jobs`・`cache_locks` | 処理すると・解くと消える | `->handledByApp('…')` |

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [
    \App\Models\PageView::class,
    \Miraqceed\Foundation\Notices\Notice::class,
]])->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('foundation:cache-prune')->daily();
```

`app/Models` のモデルだけを消すなら、`--model` なしの `model:prune` でも足りる（`--except` で除いたモデルは足りない）。パッケージのモデルは `app/Models` にないので、`--model` で渡す。**`--model` を1つでも渡すと、`app/Models` のほかのモデルは探さない。**

## 試しで止める

```php
use Miraqceed\Foundation\Testing\DataMapAssertions;

public function test_消す決まりが_すべての表と列にある(): void
{
    DataMapAssertions::assertCoversSchema();
    DataMapAssertions::assertScheduled();
}
```

- `assertCoversSchema()`：試しの DB（マイグレーションの後。`RefreshDatabase` などで作る）のすべての表が台帳にある。行を残す表のすべての列が `erase` か `keep` にある。台帳にあって DB にない表・列（名前の書き損じ・消した表）でも落ちる。PostgreSQL・MySQL では、今の接続の schema の表だけを数える
- `assertScheduled()`：`prunedBy` のモデルの `model:prune` と、`prunedByCommand` のコマンドが schedule にある（試しでは環境を問わない）
- 落ちた文に、足りないものと書き足す1行の例が出る

本番では `php artisan foundation:deploy-check` の `retention_schedule` が、今の環境で動く schedule を確かめる（`docs/deploy-check.md`）。

## 消す

```php
use Miraqceed\Foundation\Retention\Erasure;

$erased = app(Erasure::class)->erase('user', $userId, when: fn (object $user) => $user->deleted_at !== null
    && \Illuminate\Support\Carbon::parse($user->deleted_at)->lte(now()->subDays(30)));
// false：持ち主がない・when が偽（何も消していない）
```

- 1つのトランザクションで：持ち主の行を握って読み直し（`lockForUpdate()`）、その行を `when` に渡す。偽なら何もしない。**消す前に読んだ値で判断しない**（読んだ後に解約が取り消された・2つの経路で2度消す、を止める）
- `erasedWith` の表の行を消し（外部キーで親を指す表から先に）、`anonymizedWith` の表の行を書き換える。`kept`・`handledByApp` の表には触れない
- 途中で投げたら、全部が巻き戻る（投げ直す）
- 表はアプリの既定の接続に置く。ほかの接続の表は `handledByApp` にして、アプリが消す

### 出来事

消した後（コミットの後）、`Retention\Events\OwnerErased` が出る。持ち主の種類・id・表ごとの行の数を持つ。監査の記録はこれで書く（id と数だけを書き、消した値を書かない）。

```php
Event::listen(OwnerErased::class, fn (OwnerErased $event) => AuditLog::write('erased', $event->owner, $event->id, $event->counts));
```

`handledByApp` の表（ストレージのファイルなど）を消すのも、この出来事を受けて行える。ただし出来事はコミットの後なので、その消去が失敗しても台帳の消去は戻らない。

### 日次のループ

1件の失敗で、ほかの持ち主の消去を止めない。1件ずつ捕まえて記録し、次の日に流し直す。

```php
Schedule::call(function () {
    User::query()->whereNotNull('deleted_at')->where('deleted_at', '<=', now()->subDays(30))
        ->where('name', '!=', '退会したユーザー')
        ->orderBy('id')->limit(500)->pluck('id')
        ->each(function (int $id) {
            try {
                app(Erasure::class)->erase('user', $id, when: fn (object $user) => $user->deleted_at !== null
                    && \Illuminate\Support\Carbon::parse($user->deleted_at)->lte(now()->subDays(30)));
            } catch (Throwable $e) {
                app(\Miraqceed\Foundation\Failures\FailureReporter::class)->report($e);
            }
        });
})->daily()->name('erase-deleted-users')->withoutOverlapping();
```

- 選ぶ問い合わせの条件と `when` の条件は同じにする（選んだ後に変わっても、`when` が握った後に確かめ直す）
- 書き換えで行を残す持ち主は、書き換えた印（上の例では名前）で、次の日に選び直さないようにする

## 限界

- **1つのトランザクションで消せる大きさであることが前提。** 何十万行を持つ持ち主は、`prunedBy` で先に減らすか、その表を `handledByApp` にして少しずつ消す
- 外部キーが輪になる表は、消す順を決められない（`LogicException`）。自分を指す外部キー（返信の親など）は、1つの DELETE で消せる並びのときだけ
- MySQL の `UPDATE` は値の変わらない行を数えないので、`OwnerErased` の書き換えの数は、既に同じ値だった行を含まない
- 試しは、マイグレーションで作る表だけを見る。DB の外（ストレージ・外のサービス・ログ）の個人の情報は、台帳の外で消す
