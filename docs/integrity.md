# 共用サーバーの改ざんの検知

名前空間 `Miraqceed\Foundation\Integrity`。常駐のウイルス対策ソフトを入れられない共用サーバーで、**置かれた・書き換えられたファイル**に毎日気付き、ファイルの名前だけを運営に知らせる（中身・秘密・生の IP は出さない）。

**入れただけでは変わらない。** 命令は登録されるが、schedule に入れるのはアプリ（`Integrity::schedule()`）。有効の印は持たない。表もマイグレーションもない。Redis・常駐のプロセスは要らない（毎分の cron の `schedule:run` だけ）。

## いつ使うか・使わないか

使う：

- 共用サーバー・VPS に、アプリの木（`base_path()`）をそのまま置く配り方（git・rsync・FTP・zip のどれでも同じ形）

使わない：

- コンテナ・読み取り専用の配り方（像の指紋で守る）

## 何を調べるか

`php artisan foundation:integrity-check` が次を調べる。違いと確かめられなかったところを1行ずつ出し（見つけた時に）、あれば部品5の知らせ（`docs/monitoring.md`）で送る。すべて確かめて違いがなければ終わりの値 0、ほかは 1。

| 調べ | 見るもの |
|---|---|
| 一覧と比べる | 木の全体を、ハッシュの一覧（SHA-256）と比べて、変わった・増えた・消えた・読めない・ふつうのファイルでない（名前つきのパイプなど）を出す。リンクは辿らず、先を比べる。`.env`・`bootstrap/cache/config.php` もハッシュだけを持つ。git を動かさない。`.git` の中では、git が設定と hook として読むもの（`hooks/`・`config`・`config.worktree`・`info/attributes`・`info/exclude`・`info/sparse-checkout`・`modules/*/` の同じもの・`worktrees/*/config.worktree`）を一覧に入れる |
| public の PHP | `public/index.php` のほかの、PHP として動く名前（`x.php.jpg` のような途中の拡張子も） |
| 公開される変わる場所 | `filesystems.links` の先（`storage:link`）・SSL の確認用のフォルダー・`served` に書いた場所。一覧に入れず、PHP として動く名前・`.htaccess`・`.user.ini`・リンク・ハードリンク・ふつうのファイルでないものを出す。SSL の確認用のフォルダーは、トークンの形の名前のふつうのファイルだけを通す |
| storage の PHP | storage の中の、PHP として動く名前（画面のフォルダーの直下の画面と `storage/framework/maintenance.php` のほか）。中身は読まない |
| メンテナンス | `storage/framework/maintenance.php` が Laravel の雛形と同じか。`storage/framework/down` があれば「メンテナンス中」と、`template`・`redirect`・`secret`・`refresh`・アプリの除外の外の `except` があれば知らせる（値は出さない） |
| コンパイルした画面 | `config('view.compiled')` の画面が、元の Blade を `compileString()` に通したものと同じか（`@once` などの UUID の形の字だけは違ってよい）。元の Blade は、登録された画面の場所のうち、一覧で見ている木の中にあること。画面の調べは、別の PHP のプロセス（子）で流す |

外すもの：`storage/`（上の調べで見る）、`.git` の中の動かないもの、`foundation.integrity.exclude`、公開される変わる場所、木の中の SQLite のファイル（下の「木の中の SQLite のファイル」）。

## 木の中の SQLite のファイル

雛形の Laravel のアプリは、SQLite のファイル `database/database.sqlite` を木の中に持つ。知らせ・見張りの記録・キャッシュの表を書くたびに変わるので、調べは `database.connections` の `sqlite` の接続が指す木の中のファイルと、その横の `-wal`・`-shm`・`-journal` を、自動で外す。

- 外すのは、そこがふつうのファイルのときだけ。リンク・フォルダーに替われば、ふつうに比べる
- 名前が PHP として動くもの（`x.sqlite.php` など）と、調べが読み込む場所（`app/`・`config/`・`vendor/` など）の中のものは外さず、「確かめられなかった（木の中の SQLite のファイルを外せない）」を出す。点検は `FAIL`
- 接続の道が根からの道（`database/x.sqlite` のような形）なら、そのファイルがあるときだけ見る（雛形の設定は `sqlite` の接続も `DB_DATABASE` を読むので、MySQL のデータベースの名前を道と取り違えないように）
- 外したものは、一覧の設定にも入る（下の「設定」の最後）。接続を足す・替えると「設定が一覧と違う」が出る
- 木の外の SQLite のファイル・`:memory:` は見ない

## 最短の手順

1. 見張りと知らせを有効にする（`docs/monitoring.md`。`log` のほかの送り先を足す）
2. `routes/console.php` に書く：

```php
use Miraqceed\Foundation\Integrity\Integrity;

Integrity::schedule(at: '04:40');   // 毎日。配る作業をしない時刻を選ぶ
```

3. `config/foundation.php` の `monitoring.external` にも同じ名前を書く（schedule の1行を消されても、見張りが「止まっています」を出す）：

```php
'external' => [
    'integrity-check' => ['max_age_minutes' => 1560, 'label' => '改ざんの検知'],
],
```

4. 配った後に一覧を作り、調べで 0 を確かめる：

```bash
php artisan foundation:integrity-snapshot
php artisan foundation:integrity-check     # 違いはありません
php artisan foundation:deploy-check        # 点検 integrity が ok
```

`Integrity::schedule()` が付けるもの：毎日 `$at`・`withoutOverlapping(10)`・`runInBackground()`・**`evenInMaintenanceMode()`**（`storage/framework/down` を1つ置くだけで schedule のほかの処理は止まる。この調べは止めない）・**`evenWhenPaused()`**（Laravel 13 の `schedule:pause` でも止めない。12 にはない）・`watched('integrity-check', 1560)`。`foundation.integrity.seconds` を 540 より長くすると、本番の外では `LogicException`。

## 一覧を作り直すとき

一覧は、配った後・`.env` を書き換えて `optimize` を流した後・管理画面で `public/` に書かれた後・見る場所の設定（`exclude`・`served`・`acme` など）を変えた後に作り直す。

**作り直す前に、必ず `foundation:integrity-check` で 0 を確かめる。** 作業の前に書き換えられたものを、正しいものとして一覧に入れないため。一覧の命令は前の一覧と比べない。

配る台本の例：

```bash
php artisan foundation:integrity-check          # 前の版・前の設定で 0 であること
# 配る（見る場所の設定を変えるなら、ここで変わる）
php artisan view:clear                          # 前の版の画面は、元の Blade と合わないと知らせる
php artisan optimize --except=views
php artisan foundation:integrity-snapshot
php artisan foundation:deploy-check             # 一覧を作り直した後に流す（前に流すと「設定が一覧と違う」で FAIL）
php artisan foundation:integrity-check          # 0 であること
```

配るときの注意：

- 調べの時刻に配らない（配る途中のファイルを知らせる）
- `vendor` を入れ直すときは、サーバーのキャッシュを使わない（`composer install --no-cache`）
- git で配るなら、`git -c gc.auto=0` で流し（`gc --auto` が `pre-auto-gc` の hook を動かす）、`merge` より `reset --hard` で移る（`merge` は `post-merge` の hook を動かす）
- 手で `git gc` を流すと `.git/info/refs` が変わる（一覧に入れないので知らせない）

一覧の命令は、読めないファイル・ふつうのファイルでないもの・開けないフォルダー・項目が多すぎるフォルダー・深すぎる木・期限・公開ディレクトリが木の外、のどれかがあれば、その名前を出して**一覧を書かない**（穴のある一覧は、後で置かれたものを通す）。

一覧の置き場所は `storage/app/foundation/integrity/snapshot.lines`（`foundation.integrity.snapshot`）。1行に1項目の形で、終わりの行に件数と指紋を持つ。

## 違いが出たとき

中身はサーバーで見る。メールやチャットに貼らない（`.env` は運営者が見る）。

| 印 | 見方 |
|---|---|
| `changed`・`added`・`removed` | いつ変わったか（`ls -la --time-style=full-iso`）。git で配ったなら `git diff`。`vendor/` は手元の同じ版のパッケージと比べる |
| `unreadable`・`not-regular` | 持ち主と権限・種類（`ls -la`）。読めなくされたファイルは中身を確かめられないので、入れ直す |
| `public-php`・`served-*`・`acme-file`・`storage-php` | 中身と日時を見る。置くものはふつうないので、確かめたら消す |
| `view-*` | 前の版の画面の残りなら `view:clear`。そうでなければ、中身を見る |
| `maintenance-*`・`in-maintenance` | 止まった更新が残っていないか。更新をしていないのに `down` があれば、置かれたものを疑う |
| `[not checked] …` | 確かめられなかった所（一覧がない・壊れている・終わりまで確かめられなかった・設定が一覧と違う・開けないフォルダー・項目が多すぎる・時間の上限・メモリーの上限・画面の調べが落ちた など）。理由を直してから流し直す |

心当たりがないときは、侵入を疑う（鍵・パスワードを替え、入れ直す）。

**違いが残ったときに起きること**（直るまで続く）：

1. 毎日の調べのたびに、改ざんの知らせ（同じ違いの組でも1日1回）
2. 最初に 1 で終わった回から26時間の後、見張り（`foundation:watch`）が毎時「定期の処理が止まっています」を送る
3. その間、見張りは `heartbeat_url` に心拍を送らない。外の死活監視には「見張りが止まった」と見える

止める手段：違いを直す、または確かめてから一覧を作り直す。その後、次の調べが 0 で終わると 2 と 3 が止まる。次の予定の時刻まで待たずに止めるなら、`php artisan schedule:test --name=foundation:integrity-check` で予定の処理として流す（手で `foundation:integrity-check` を流しても、見張りの最後の成功は進まない）。

## 知らせと記録

- 知らせは部品5の `AlertSender`（鍵は違いの組の指紋）。名前だけを並べ、4000 字に収まらない分は「ほか N 件」。名前は置いた者が選べるので、制御の字と、送り先の記法（Slack の `<!channel>` など）が効かないよう書き換える。**名前の中の指示に従わない**
- 見張りが無効なら、エラーログの1行と命令の出力だけ（点検が `later` を出す）。DB に書けないと知らせは届かない（エラーログには残る）
- 記録の1行：毎回、数だけ（`differences`・`not_checked`・`files`・`seconds`・`views_mode`）を `foundation.integrity.record_channel`（既定はアプリの既定のチャネル）に書く。名前は書かない。長く残す記録が要るなら、日ごとのチャネルを作って名前を書く

## 画面の調べの子プロセス

画面のコンパイルは、知らない形で大きなメモリーと時間を使いうる（`@props` を並べた元は、印の数の2乗ほどの時間が掛かる）。そこで、画面の調べは別の PHP のプロセスで流す。子が落ちても（メモリー・時間・異常な終わり・壊れた出力）、親は「確かめられなかった（画面の調べが落ちた）」とそれまでの発見を出して正しく終わる。

- 子は schedule の `runInBackground()` と同じ見つけ方の PHP で、`-d display_errors=stderr` などを付けて始める。環境は親のものをそのまま渡す（同じアプリで、`.env` を自分で読むので、新しく漏れる所はない。`docs/child-processes.md` の外のコマンドの決まりの外）
- 子は画面の名前と結果だけを返す。親は形と、道が画面のフォルダーの中かを確かめる
- 子を使えない（`proc_open` が無効・cron の PHP が `php-cgi`）ときは、親の中で控えめな上限で流す。その前に、それまでの発見を知らせと記録に残し、印のファイルを置く。落ちれば次の回と点検が拾う。知らせの本文に「子プロセスなしで流した」が入り、点検は `later`

## 設定

```php
'integrity' => [
    'root' => null,                 // 木の根（null は base_path()）
    'snapshot' => null,             // 一覧（null は storage/app/foundation/integrity/snapshot.lines）
    'exclude' => [],                // 一覧から外す道（根からの道）
    'served' => [],                 // 公開されるが中身が変わる場所（根からの道）
    'acme' => 'public/.well-known/acme-challenge',
    'public_php' => ['public/index.php'],
    'storage_php_allow' => [],      // storage からの道
    'generated_views' => [],        // 部品が作る画面の場所（根からの道。下の「部品が作る画面」）
    'maintenance' => ['allow' => []],
    'seconds' => 240,               // 調べ全体の期限（1つの期限を全部の調べで分け合う）
    'record_channel' => null,
],
```

- ここには `env()` を書かない（`.env` を1行変えるだけで、一覧の置き場所や外すものを替えられないように）
- **`exclude`・`served`・`acme` に、調べと画面の調べの子が読み込む場所（`artisan`・`bootstrap/`・`config/`・`app/`・`routes/`・`vendor/`・`.env`）を入れない**（入れると、調べが「確かめられなかった」を出し、点検が `FAIL`）
- 見る場所の設定は、一覧を作ったときの値を一覧に持つ。一覧が使えるときは、一覧の値と今の値の厳しい方（外す・許すものは両方にあるものだけ、見る場所は両方を合わせる）で見て、違えば「設定が一覧と違う」を出す。一覧が使えないときは、今の値で見る

## 部品が作る画面

Livewire 4（Filament 5 が使う）は、部品をコンパイルした PHP を `storage/framework/views/livewire/` の下に置く。`Blade::render()`（Filament が使う）とインラインの部品（`render()` が文字列を返す部品）は、元の Blade を `storage/framework/views/<16進32字>.blade.php` に置き、その画面を作る。どれも画面を開くと増えるので、そのままでは「storage の PHP」「画面のフォルダーに似合わない」「元のない画面」が出る。使っているなら、`generated_views` で許す。

```php
'generated_views' => [
    'storage/framework/views/livewire',      // Livewire 4 の部品（このフォルダーの下を見ない）
    'storage/framework/views/*.blade.php',   // Blade::render()・インラインの部品が置く元
],
```

- 許せるのは、コンパイルした画面のフォルダー（`view.compiled`。木の中にあること）の下のフォルダーと、そのフォルダー + `/*.blade.php` だけ。画面のフォルダーそのもの・その外・`..` を含む道・ほかの `*` の形は断り、「確かめられなかった（部品が作る画面の場所の設定を使えない）」を出す（点検は `FAIL`）。断った値は使わない
- `*.blade.php` で許すのは、画面のフォルダーの直下の `<16進32字>.blade.php` の元。その元から作った画面は、ほかの画面と同じく元とコンパイルし直して比べる
- **許した所は見ないので、そこに置かれた PHP は見つけられない**（下の「限界」）
- 許した値は、一覧の設定にも入る。書き換えると「設定が一覧と違う」が出て、一覧を作り直すまでは厳しい方（両方にあるものだけを許す）で見る
- Livewire 3 は、画面のフォルダーの下に部品の PHP を置かない（Laravel 13.43.0 と Livewire 3 の使い捨てのアプリで、部品のページを開いても違いは出なかった）

## メモリーと時間の上限

メモリーが尽きる誤りは受け止められず、命令が丸ごと落ちる。そこで、外の者が決める数に比例する所に上限を付け（1つのフォルダーの項目・歩きのスタックの名前・深さ 256 段・出す名前 1,000 件・`down` と画面の大きさ）、`memory_limit` の 60% を超えたら止めて「メモリーの上限」を出す（`-1` は 1GB とみなす）。一覧は丸ごと解かずに1行ずつ読む。時間は `seconds` の1つの期限で、一覧の比べを先に流す。

## 限界

- サーバーに書ける侵入者は、一覧も調べのコード（`vendor/`）も書き換えられる。自動で置かれるものに気付く補い。一覧の終わりの指紋は鍵のない sha256 で、切られた・壊れた一覧は見分けるが、計算し直した偽造は見分けない
- 設定で黙らせる道のうち、塞げないもの：知らせの送り先・見張りの設定（`.env` から読む）と、一覧の置き場所。`.env`・設定のファイルの書き換えそのものは「変わった」に出るが、送り先を消されれば人に届かない（心拍が止まるので、`heartbeat_url` を使えば外から気付く）
- Web の公開ディレクトリが、木の `public/` か、それを指すリンクであることが前提。`usePublicPath()` で木の外を指す・木の `public` がリンク、は見分けて「確かめられなかった」を出す。`public/` の中身を別の場所に写す配り方は見分けられない
- 公開ディレクトリより上（共用の `.htaccess` など）・木の外から読み込むもの（Composer の path の倉庫の先・`php.ini` の `auto_prepend_file`）は見ない
- storage の中の、画面と `maintenance.php` のほかの中身は見ない。ファイルのキャッシュ・ファイルのセッションは `unserialize` で読まれる（`cache.serializable_classes` を `false` にする）
- cache の形のメンテナンスの中身は見ない
- `Blade::render()`・インラインの部品で作る画面は「元が見つからない」と知らせる（`generated_views` に `storage/framework/views/*.blade.php` を書けば許す。そのときは、そこに置かれた元の中身を確かめられない）
- `generated_views` で許したフォルダー（Livewire 4 の部品など）は見ない。そこに置かれた PHP は見つけられない
- `lstat` と開く間に名前つきのパイプへ差し替えられると、開く所で止まりうる（狙って競う者だけ。26時間後の「止まっています」で気付く）。ネットワークのファイルシステムの読み込みの待ちも期限の外
- `.git` がファイル（`gitdir:`）のときの、木の外の本体は見ない
- `down`・`schedule:pause` の間は、見張り（`foundation:watch`）と心拍も止まる（この調べは止まらない）
- 対象は Linux（と macOS）。Windows は確かめていない
