# 外のデータのファイルを安全に入れ替える

名前空間 `Miraqceed\Foundation\DataFiles`。アプリが外から取ってくるデータのファイル（IP の位置の DB・Public Suffix List・使い捨てのメールのドメインの一覧など）を、本番の cron で**動いているものを壊さずに**入れ替える。命令 `foundation:data-files`・読む側の助け `DataFiles`・点検 `data_files`。

- **入れただけでは何も変わらない。** 種類（`foundation.data_files.sources`）は既定で空。命令は流しても「種類がない」と言って何も書かない。schedule には入れない（入れるのはアプリ）
- **パッケージは外のデータを持たず、配らない。** どこから取るか・帰属の表示・取る頻度は、アプリが配る側の決まりに合わせて決める（下の「配る側の決まり」）
- 依存を足さない。データを読むライブラリ（`geoip2/geoip2`・`jeremykendall/php-domain-parser` など）は、アプリが入れる

## いつ使うか・使わないか

| 使う | 使わない |
|---|---|
| 本番で cron から定期に取り直すデータのファイル（月ごとの IP の位置の DB、週ごとの PSL など） | 開発の時に作ってアプリの倉庫に入れるデータ（郵便番号の一覧は `foundation:postal-codes`。`docs/japan-address.md`） |
| 取ったものを、そのまま（か gzip を解いて）1つのファイルとして読むデータ | DB の表に入れる一覧（この部品の外。すべて確かめてから1つのトランザクションで入れ替え、空・大きな減りで止める。`DELETE` を使い、確定が先に走る `TRUNCATE` を使わない） |

自分で `Http::get()` → `file_put_contents()` と書くと、次の穴を踏む。この部品はそれぞれを止める。

- 誤りのページ（200 の HTML）・途中で切れたファイル・種類の違うファイルで、動いているものを上書きする → **使う側と同じ読み方の確かめ（`verify()`）を通ったものだけ入れる**
- CDN や配り直しが返した古い版で、新しいものを戻す → **版を比べ、新しいときだけ入れる**
- 手で流した更新と予定の更新が重なる（schedule の `withoutOverlapping()` は手で流したものを守らない）→ **ファイルのロック（`flock`）。版の比べと入れ替えはロックの中**
- 一時のファイルを `sys_get_temp_dir()` に置く（別のファイルシステムでは、PHP の `rename()` は写してから消す形になり、原子的でない）→ **一時のファイルは必ず置き場所と同じディレクトリ**
- gzip を上限なく解く（配る側の誤りでディスクを埋める）・切れた gzip を途中まで黙って読む → **解いた大きさの上限と、終わりまで解けたかの確かめ**
- 読む側が、入れ替えた後も古い結果を使い続ける・解いた結果の写しを `unserialize()` で何でも作る → **変わったかの鍵（inode も）と、`allowed_classes` を絞った写し**
- データがないと、それを使う判定が黙って効かなくなる → **間引いて知らせる。点検で見る**

## 最短の手順

1. 種類を書く（下の「種類の書き方」）
2. 並べる：

```php
// config/foundation.php
'data_files' => [
    'sources' => [App\Data\IpCityDatabase::class],
],
```

3. 流して確かめる：`php artisan foundation:data-files`
4. schedule に入れる（配る側の頻度に合わせる。流すたびに丸ごと取る。下）。止まったことに気付けるよう、見張り（`docs/monitoring.md`）を付ける：

```php
// routes/console.php：月ごとに出るデータなら週に1回など
Schedule::command('foundation:data-files')->weeklyOn(1, '04:30')->watched('data-files', 8 * 24 * 60);
```

種類ごとに頻度を分けるなら、名前を渡して別々に入れる（例 `foundation:data-files public-suffix-list` を毎日、`foundation:data-files ip-city` を毎週）。

5. 読む所で `DataFiles` を使う（下の「読む側の助け」）

## 種類の書き方 `DataSource`

```php
use Carbon\CarbonImmutable;
use Miraqceed\Foundation\DataFiles\DataSource;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Limits;

final class SampleList implements DataSource
{
    // 置き場所・ロック・知らせの鍵（1〜64字の ASCII の英数字・-・_）
    public function name(): string
    {
        return 'sample-list';
    }

    // 試す順の取り方
    public function downloads(CarbonImmutable $now): iterable
    {
        yield Download::url('https://data.example.com/list.txt');
    }

    // 既定はない。必ず渡す
    public function limits(): Limits
    {
        return new Limits(maxDownloadBytes: 5 * 1024 * 1024, maxBytes: 5 * 1024 * 1024, minBytes: 1024, maxShrink: 0.2, staleAfterDays: 14);
    }

    // 使う側と同じ読み方で開き、決まった答えが返るかを見て、版を返す。だめなら投げる
    public function verify(string $path): string|int|null
    {
        $text = (string) file_get_contents($path);
        if (! str_ends_with($text, "# end\n")) {
            throw new RuntimeException('cut');
        }

        return preg_match('/^# version: (\S+)$/m', $text, $m) === 1 ? $m[1] : throw new RuntimeException('no version');
    }

    // 置き場所にないときに読む写し（アプリの倉庫に入れたもの）。なければ null
    public function fallbackPath(): ?string
    {
        return null;
    }
}
```

種類は容器から作る（命令を流したとき・読む側の助けを呼んだとき）。プロバイダーの起動では作らない。

### 確かめ `verify()`

- **使う側と同じ口で開き、決まった答えを見る。** 開けるだけでは足りない（種類違いの DB も開ける。IP の位置の DB なら、使う `city()`・`asn()` で引く）
- **終わりの印があれば、見る。** 終わりの印を求めないライブラリは、切れたデータも読める（PSL の読み手は `===END PRIVATE DOMAINS===` を求めない）
- 取ったファイル（一時のファイル）にも、入っているファイルにも呼ぶ（入っている版を知るため。別に版の記録を持たない）。入っているファイルが開けなければ、入っていないのと同じに扱う
- 投げた例外の文は、命令の出力・知らせに出さない（例外の種類だけを出す）
- **版**：整数は数の順、文字列は文字の順で比べる（文字の順で並ぶ形にするのは種類の責任。`2026-10-07_07-28-19_UTC` など）。取ったものが入っている版**以下**なら入れない（`--force` でも）。整数と文字列が混ざれば失敗
- 版を持たないデータは `null` を返す。中身の SHA-256 で比べ、同じなら入れない・違えば入れる（**古い版で戻すことは防げない**）

### 取り方 `Download`

| | |
|---|---|
| `Download::url($url)` | https だけ。http・相対・userinfo つき（`https://user:pass@…`）は `InvalidArgumentException` |
| `->gzip()` | 本文を gzip として流しながら解く（解いた大きさの上限 `maxBytes`・終わりまで解けたかを見る。つないだ gzip も解く。後ろのごみ・gzip でない本文は失敗） |
| `->notFoundMeansNotYet()` | 404 を「まだ出ていない」として次の取り方へ進む（月ごとに出るデータの今月の分）。すべてが「まだ」で入っているものがあれば成功、なければ失敗 |
| `->followRedirects()` | https へのリダイレクトに3回まで従う（既定は従わない。3xx は失敗）。https から http へは従わない。`withHeaders()` で見出しを足した取り方では、ほかの元（スキーム・ホスト・番号）へのリダイレクトに従わない（失敗にする。同じ元の中では従う） |
| `->withHeaders([...])` | 見出しを足す（鍵の要る取り方）。値は失敗の文・記録に出さない。足した見出しを、リダイレクトの先のほかのホストへ送らない（上） |

ZIP・tar はこの部品では解かない（大きな DB をメモリーに読むと、共用サーバーの上限に当たる）。

### 上限 `Limits`

`new Limits(maxDownloadBytes, maxBytes, minBytes, maxShrink, staleAfterDays)`。どれも既定はない（データによって、ふつうの大きさと減りの幅が違う）。

- `maxDownloadBytes`：取る大きさの上限。名乗る大きさ（`Content-Length`）と、受けている途中の大きさの両方で見る
- `maxBytes`・`minBytes`：解いた大きさの上下（空・誤りのページを断る）
- `maxShrink`：入っているファイルより、この割合を超えて小さければ断る（切れたデータが確かめを通るときの止め）。`--force` で通す。`null` なら比べない
- `staleAfterDays`：最後に更新を流して成功してから、この日数を過ぎれば点検が `later`。`null` なら見ない

## 命令 `foundation:data-files`

```bash
php artisan foundation:data-files              # 登録したすべての種類を、1つずつ更新する
php artisan foundation:data-files sample-list  # 1つだけ
php artisan foundation:data-files --force      # 減りの止めだけを通す（確かめは通す。古い版・同じ版は入れない）
php artisan foundation:data-files --status     # 置いたファイル・版・最後に成功した更新（取りに行かない）
```

1つの種類の流れ（ロックの中）：

```
ロックを取る（flock・待たない）── 取れない → 「ほかの実行が動いている」で成功
  │ 1時間より古い一時のファイルを消す
  │ 入っている版 = verify(置き場所)
  ▼
downloads() の順に：
  取る（https・200 だけ・リダイレクトは既定で従わない・取る大きさの上限・全体 600 秒・つなぐ 10 秒・User-Agent はパッケージの名前）
  置き場所と同じディレクトリの一時のファイルに書く（gzip なら流しながら解く）
  大きさの下限・上限 → verify(一時) → 版を比べる（新しくなければ入れずに成功）→ 減りの止め
  fsync → 権限を入っているファイルにそろえる → rename
ロックを放す（一時のファイルは必ず消す）
```

- 種類ごとに別々に更新する（1つの失敗で、ほかを止めない）。1つでも失敗すれば終わりの値 1
- 失敗は `FailureReporter`（`docs/failures.md`）で知らせる。種類の名前と理由だけ（URL は問い `?` から後と userinfo を落とし、見出しの値・データの中身・元の例外を入れない）。間引かない（schedule の出力は捨てられることが多い）
- 全体の時間の上限は 600 秒、つなぐ時間の上限は 10 秒（PHP の cURL で送るとき）
- どの失敗でも、置き場所は前のまま・一時のファイルは残らない

### 置き場所

```
storage/app/foundation/data-files/     ← foundation.data_files.path（null なら左）
  sample-list/
    data        ← 読む側が読むファイル（名前は固定）
    .lock       ← flock のファイル（消さない）
    .checked    ← 最後に更新を流して成功した時刻（mtime）
    .tmp-…      ← 一時のファイル（同じディレクトリ）
```

- 一時のファイルとロックは、置き場所と一緒に動く（替えられない）
- 置き場所のディレクトリは 0775、入れるファイルは入っているファイルと同じ権限（初めては 0644 に umask）
- `.checked` は、入れた・新しくなかった・まだ出ていない（入っているものがある）のどれでも今になる。配る側が長く新しい版を出さないだけなら古いと言わず、更新が止まった・失敗し続けるときに古いと言う

### schedule と配る側の頻度

- schedule の `withoutOverlapping()` を付けてもよいが、手で流したものは守らない（この部品のロックが守る）
- **取る頻度は、配る側の決まりより多くしない**（下の表）
- **流すたびに、ファイルを丸ごと取る。** 取った後に版を比べ、新しくなければ入れない（「新しくない」で成功）が、取る分の負荷は配る側にかかる。入っているものが最新なので取らない、という口はない（条件つきの要求も送らない）。`notFoundMeansNotYet()` と月の URL で今月 → 先月と試す形でも、今月の分が出るまでは毎回先月の分を、出た後は毎回今月の分を丸ごと取る
- なので、月ごとに出るデータ（IP の位置の DB）は週に1回などにし、`staleAfterDays` と見張りの許す古さをそれに合わせる（例：週に1回なら `staleAfterDays: 21`・`->watched('data-files', 8 * 24 * 60)`）。PSL は1日1回まで

## 読む側の助け `DataFiles`

```php
use Miraqceed\Foundation\DataFiles\DataFiles;

$files = app(DataFiles::class);   // 容器の singleton

$path = $files->path('sample-list');       // ?string。置き場所 → 写し（fallbackPath()）→ null
$key = $files->changeKey('sample-list');   // ?string。道・inode・mtime・大きさ。変わったら開き直す

// 解いた結果を、同じプロセスでは1回だけ解き、プロセスをまたいでは写しを読む（PHP-FPM は要求をまたいで static を持たない）
$rules = $files->remember('public-suffix-list', fn (string $path) => Rules::fromPath($path), allowedClasses: [Rules::class]);
```

- `path()` が `null` のとき・写しに戻ったときは、`foundation-data-files-missing:{名前}` の鍵で1時間に1回だけ知らせる（プロセスをまたいで。`local`・`testing` の環境では黙る）。データがないと、それを使う判定が黙って効かなくなるため
- 登録していない名前は `LogicException`（書き違いを黙って `null` にしない）
- `changeKey()`：時刻を保った写し（`rsync -t`）でも、rename で入れ替えれば inode が変わる
- `remember()`：写しは `foundation.data_files.cache_path`（null なら `storage/framework/foundation/data-files-cache`）に serialize で置き、`unserialize($s, ['allowed_classes' => $allowedClasses])` で読む。**`allowedClasses` は必ず渡す**（空の配列も可）。許していない物（`__PHP_Incomplete_Class`）を含む・壊れた写しは捨てて解き直す。置けなくても、解いた値を返す。PHP のファイル（`var_export`）にしない（storage の PHP のファイルは、改ざんで動くコードになる）
- 長く動くプロセス（キューのワーカー）でも、`changeKey()`・`remember()` を呼ぶたびに入れ替えを見て、次の呼び出しから新しいファイルを読む。開いたままの読み手（DB のライブラリの Reader など）を持つなら、鍵が変わったら開き直す

## 点検 `data_files`（`foundation:deploy-check`）

種類がなければ `skip`。置き場所にファイルがない → `FAIL`（写しを読んでいるだけなら `later`）。最後に更新を流して成功してから `staleAfterDays` を過ぎた・まだ一度も成功していない → `later`（古くても読めるので止めない。`--strict` で失敗）。

## 配る側の決まり（2026-11-23 に、各社の文書で確かめた範囲）

| 配る側 | ライセンス・帰属 | 取る頻度・取り方 |
|---|---|---|
| DB-IP の Lite（IP to Country・City・ASN Lite。https://db-ip.com/db/lite.php） | CC BY 4.0。データの帰属を DB-IP.com に。Web では、結果を出す・使うページに DB-IP.com へのリンク（例 `<a href='https://db-ip.com'>IP Geolocation by DB-IP</a>`）。取る前に、ライセンスに同意する印を付ける形になっている | 毎月更新。取る頻度の決まりは文書にない。取得の URL は `https://download.db-ip.com/free/dbip-{版}-lite-{YYYY-MM}.mmdb.gz`（各版の取得のページに載っている形。リダイレクトなしで答え、`Content-Length` を名乗る） |
| MaxMind の GeoLite（https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/） | GeoLite の EULA。アカウントとライセンスキーが要る。自社の中の利用（EULA 3節）・第三者に開示しない（6.1節）。帰属 "This product includes GeoLite Data created by MaxMind"。新しい版が出てから30日の内に古い版を消す（6.3節）。**利用者に見せるサービスで使えるかは、EULA を読み、要れば MaxMind に問う**（パッケージは判断しない） | 1日30回まで。鍵は `withHeaders()` か、取り方の決まりに合わせて渡す（失敗の文には出ない） |
| Public Suffix List（https://publicsuffix.org/list/） | MPL 2.0（ファイルの頭） | **1日1回より多く取らない。** `https://publicsuffix.org/list/public_suffix_list.dat` からだけ取る（ほかの写しから取らない）。UTF-8 |

この表は確かめた日の内容。使う前に、各社の今の文書を読む。帰属の表示はアプリの画面で出す。

## 例（コードは説明書の中。パッケージはこれらのライブラリに依存しない）

### IP の位置の DB（DB-IP の City Lite・`geoip2/geoip2`）

```php
use Carbon\CarbonImmutable;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Miraqceed\Foundation\DataFiles\DataSource;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Limits;

final class IpCityDatabase implements DataSource
{
    public function name(): string
    {
        return 'ip-city';
    }

    public function downloads(CarbonImmutable $now): iterable
    {
        // 月の初めに1回出る（UTC）。今月の分がまだなら先月の分
        foreach ([$now, $now->subMonthNoOverflow()] as $month) {
            yield Download::url('https://download.db-ip.com/free/dbip-city-lite-'.$month->format('Y-m').'.mmdb.gz')
                ->gzip()
                ->notFoundMeansNotYet();
        }
    }

    public function limits(): Limits
    {
        return new Limits(maxDownloadBytes: 300 * 1024 * 1024, maxBytes: 600 * 1024 * 1024, minBytes: 1024 * 1024, maxShrink: 0.2, staleAfterDays: 45);
    }

    public function verify(string $path): int
    {
        $reader = new Reader($path);
        try {
            try {
                // 使う口（city()）で引く。種類違いの DB（ASN など）は BadMethodCallException
                $reader->city('192.0.2.1');
            } catch (AddressNotFoundException) {
                // 読めた。この番地がないだけ
            }

            return $reader->metadata()->buildEpoch;
        } finally {
            $reader->close();
        }
    }

    public function fallbackPath(): ?string
    {
        return null;
    }
}
```

読む所：`$path = app(DataFiles::class)->path('ip-city')`。`null` なら国・地域を空にする（知らせは助けが出す）。`Reader` を長く持つなら、`changeKey('ip-city')` が変わったら開き直す。

### Public Suffix List（`jeremykendall/php-domain-parser`）

```php
use Carbon\CarbonImmutable;
use Miraqceed\Foundation\DataFiles\DataSource;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Limits;
use Pdp\Rules;
use RuntimeException;

final class PublicSuffixList implements DataSource
{
    public function name(): string
    {
        return 'public-suffix-list';
    }

    public function downloads(CarbonImmutable $now): iterable
    {
        yield Download::url('https://publicsuffix.org/list/public_suffix_list.dat');
    }

    public function limits(): Limits
    {
        return new Limits(maxDownloadBytes: 5 * 1024 * 1024, maxBytes: 5 * 1024 * 1024, minBytes: 100 * 1024, maxShrink: 0.1, staleAfterDays: 21);
    }

    public function verify(string $path): string
    {
        $text = (string) file_get_contents($path);
        // 読み手は終わりの印を求めないので、切れた一覧も読めてしまう。印を見る
        if (! str_contains($text, '// ===END PRIVATE DOMAINS===')) {
            throw new RuntimeException('the list is cut');
        }
        if (preg_match('#^// VERSION: (\S+)$#m', $text, $match) !== 1) {
            throw new RuntimeException('no version');
        }
        $rules = Rules::fromString($text);
        // ICANN の部と私的な部の規則で、決まった答えが返る
        if ($rules->resolve('www.example.co.uk')->registrableDomain()->value() !== 'example.co.uk'
            || $rules->resolve('www.example.github.io')->registrableDomain()->value() !== 'example.github.io') {
            throw new RuntimeException('unexpected answers');
        }

        return $match[1]; // "2026-10-07_07-28-19_UTC" の形。文字の順で並ぶ
    }

    public function fallbackPath(): ?string
    {
        // アプリの倉庫に入れた写し（初めて流す前・取れない間に読む）
        return resource_path('psl/public_suffix_list.dat');
    }
}
```

```php
// 読む所：解いた Rules を写しに置き、要求ごとに解かない（解くのに約100ミリ秒かかる）
$rules = app(DataFiles::class)->remember('public-suffix-list', fn (string $path) => Rules::fromPath($path), allowedClasses: [Rules::class]);
```

schedule は1日1回まで（例 `->dailyAt('04:30')`）。倉庫の写しは古くなるので、点検の `later`（写しを読んでいる）で気付く。

### 使い捨てのメールのドメインの一覧（`docs/email-input.md` の `NotDisposableEmail`）

```php
public function name(): string { return 'disposable-domains'; }

public function downloads(CarbonImmutable $now): iterable
{
    yield Download::url('https://lists.example.com/disposable-domains.txt');
}

public function verify(string $path): ?string
{
    $lines = array_filter(array_map('trim', file($path) ?: []), fn ($line) => $line !== '' && ! str_starts_with($line, '#'));
    if (count($lines) < 1000) {
        throw new RuntimeException('too short');
    }

    return null; // 版を持たない一覧：中身が同じなら入れない
}
```

```php
// config/foundation.php
'mail' => ['disposable_domains' => storage_path('app/foundation/data-files/disposable-domains/data')],
```

`DisposableEmailDomains` は1つのプロセスで1回だけ一覧を読む。長く動くキューのワーカーは、入れ替えた後も古い一覧のまま（ワーカーを入れ替えの後に回し直す。cron で回すワーカー（`docs/queue.md`）なら、次の回から新しい一覧）。

## 限界

- **読む側から見て rename が原子的なのは、Linux のふつうのファイルシステム（ext4・xfs・overlayfs など）だけ。** Docker Desktop の bind mount（Windows・macOS のドライブを写したもの）や、ネットワークのファイルシステムでは、入れ替えの最中に空のファイルを読むことがある。**本番の置き場所はふつうのファイルシステムにする。** 確かめた結果（2026-11-23、PHP 8.4、Docker Desktop の上の Linux のコンテナ）：
  - 書いて fsync して rename するだけの小さな確かめ（2ms おきに200回入れ替える間、別のプロセスが読み続ける）：ext4（Docker のボリューム）で 165,191 回・overlayfs（コンテナの中の `/tmp`）で 141,390 回の読みのうち、途中・空のファイルは 0 回。Windows のドライブを写した bind mount（9p）では、計8回・8,349 回の読みのうち 1 回が空のファイル
  - この部品の読む側の並べる試し（入れ替え50回の間、別のプロセスが読み続ける）：overlayfs・ext4 では通る（途中・空のファイルは 0 回）。置き場所を bind mount（9p）の上に置くと、計6回とも空のファイルを読んで落ちた（1,309 回のうち 11 回・1,315 回のうち 10 回・1,314 回のうち 9 回・701 回のうち 13 回・697 回のうち 15 回・691 回のうち 14 回。約0.7〜2.2%）
  - xfs・macOS のドライブを写したもの・ネットワークのファイルシステムは確かめていない（一般の性質からの見込み）
- **共用サーバーの storage では、rename・`flock`・`fsync()` の振る舞いを確かめていない**（確かめたのは上のコンテナの中と、CI の ubuntu だけ）。本番のアプリに入れるときに、その共用サーバーで確かめる（同じ置き場所で `foundation:data-files` を2つ同時に流し、片方が「ほかの実行が動いている」になること・入れ替えの後に読む側が新しいファイルを読むこと、など）
- 版を持たないデータ（`verify()` が `null`）は、古い版で戻すことを防げない
- `flock` が効かないファイルシステム（NFS などの一部）では、重なりを止められない。置き場所はローカルのディスクにする
- `rsync --inplace` で写すと inode が変わらず、大きさと時刻も同じなら `changeKey()` が変わらない（ふつうの `rsync` は一時のファイル + rename なので変わる。大きさと時刻が同じなら写さない）
- Windows は確かめていない（動くはずだが、読んでいる最中のファイルへの入れ替えは失敗しうる。失敗として知らせ、前のものを残す）
- 全体の時間の上限 600 秒を超える大きなデータは取れない
- 取ったものはファイルにだけ置く。DB の表に入れる一覧は、この部品の外
