## miraqceed/laravel-foundation

どのアプリにも要る部品（テストの守り・CSV・ログインのロック・CSP・見張りと知らせ・AI の費用の上限等）を1つにまとめたパッケージ。設定を持つ部品は `config/foundation.php` で有効にする。CSV・非負整数の計算・予約メールドメインの判定と入力の検証の規則・秘密を伏せる部品・メールの本文の打ち消しは、設定なしで呼び出せる。**ただしテストの DB の守りだけは入れた時点で効く**（テストの DB の名前に `test` か `testing` が語として要る。止まったら `docs/test-safety.md`）。自前で書き直す前に、`vendor/miraqceed/laravel-foundation/docs/` の説明書（`test-safety.md`・`csv.md`・`files.md`・`uploads.md`・`integer-math.md`・`reserved-email-domains.md`・`mail-safety.md`・`redaction.md`・`login.md`・`attempts.md`・`two-factor.md`・`one-time-codes.md`・`once-notices.md`・`retention.md`・`database-timezone.md`・`database-locks.md`・`deploy-check.md`・`failures.md`・`http.md`・`validation-ja.md`・`child-processes.md`・`security.md`・`health.md`・`monitoring.md`・`queue.md`・`ai.md`・`ssrf.md`）を読む。

### テストの守り（`docs/test-safety.md`）

- 入れた時点で効く（有効にする設定はない）。テスト用 DB の名前に `test` か `testing` を**語として**入れる（`app_test`）。印のない DB につなごうとしたテストは DB に触れる前に止まる。
- 接続先を環境変数（`--env-file`・`-e DB_DATABASE=…`）で渡さない。`.env.testing` か `phpunit.xml` に書く。
- テストで読む外の DB は、名前を偽らずに `foundation.test_safety.ignore_connections` に接続名を挙げる。
- 並列の試し（`--parallel`）では、元のテスト用 DB を先に作る（ないと `Unknown database` で全部落ちる）。
- 新しいアプリでは `foundation.test_safety.prevent_stray_requests` を真にする（テストの中だけ、`Http::fake()` に当たらない外への要求を送る前に止める。既定は偽）。本物の通信が要る試しは、その中で `Http::allowStrayRequests()`。SDK が自分で送る通信は止まらないので、試しでは鍵を `phpunit.xml` で空に固定する。

### CSV（`docs/csv.md`）

- 書き出しは `CsvResponse::download()`。式の守り・BOM・Shift_JIS・途中で失敗した印は内蔵。自前の `fputcsv` や `'` の付与をしない。
- `lazy()`・`chunk()` で書き出さない（行が重複・欠落する）。`Rows::cursor()` はずれないがメモリーは減らない。大きな表は `Rows::keyset()`（一意の列を非修飾の名前で渡す）。
- 読み込みは `CsvReader::parse()`（UTF-8・BOM 付き・Shift_JIS を判定する。外れるときは `SourceEncoding` を渡す）。

### ファイル名とファイルの中身（`docs/files.md`・`docs/uploads.md`）

- 利用者が書いた名前をダウンロードの名前に入れるときは `\Miraqceed\Foundation\Files\FileName::name($base, 'csv')` と `FileName::disposition($name, fallback: 'list')`。`/` を含む名前をそのまま `response()->download()` に渡さない（500 になる）。バイトで切らない（書記素の切れ目で200バイト）。
- 上げたファイルの名前は `FileName::forUpload()` で表示と列に入れる。保存のキーにはアプリが作る名前（ULID）を使う。
- 上げる規則は `['file', 'max:…', 'mimes:…', 'extensions:…']`（画像は `image`）。`image:allow_svg`・`mimes:svg` を書かない。ストレージに直に上げたファイルは `FileSignature::fromStream()` で先頭を確かめる。
- 画像は `ImageMetadata::strip($mime, $bytes)` で位置の情報を消してから保存する（向きは残る。壊れた画像は例外）。配るときは `attachment` と `nosniff`。直に上げる流れの手順は `docs/uploads.md`。

@verbatim
<code-snippet name="CSV を書き出す" lang="php">
use Miraqceed\Foundation\Csv\CsvResponse;
use Miraqceed\Foundation\Csv\Rows;

return CsvResponse::download('一覧.csv', ['ID', '会社名'], (function () {
    foreach (Rows::keyset(Company::query(), 'id') as $c) {
        yield [$c->id, $c->name];
    }
})());
</code-snippet>
@endverbatim

### 非負整数の計算（`docs/integer-math.md`）

- PHP の整数の上限を超える非負整数の計算には `Miraqceed\Foundation\Support\UnsignedInteger` の `add`・`multiply`・`ceilDivideByPowerOfTen`・`compare` を使う。和・積・切り上げの結果は数字列、比較は `-1`・`0`・`1`。float に変換してから渡さない。
- 値は実際の非負 int または ASCII の数字列、`scale` は実際の非負 int。入力の桁数の制限と、結果を DB・JSON へ保存できる範囲はアプリで決める。

### 実在しないメールアドレス（`docs/reserved-email-domains.md`）

- 予約のドメイン（`example.com`・`.invalid`・`.test`・点のないドメインなど）の判定は `Miraqceed\Foundation\Mail\ReservedEmailDomains::isReserved()` を呼ぶ。予約の一覧や判定を自前で書かない（末尾の点・全角・句点をそろえてから比べる判定がある）。
- 後でメールを送る欄（登録・メールアドレスの変更・招待）には `new NotReservedEmailDomain` を付ける。ログインの欄には付けない。
- アプリが作る宛先（退会した利用者の伏せ字・E2E の利用者）は、`foundation.mail.drop_reserved_recipients` を真にして送る直前に外す（既定は無効）。有効にするとテストの中でも効くので、`array` のメーラーで確かめる試しの宛先に `example.*`（`fake()->safeEmail()` が作る）を使わない。`Mail::fake()` は影響しない。
- **メールアドレスの形の確かめではない。** `false` でも形が正しい・届く保証はない。形は `email` の規則で確かめる。

### メールアドレスの入力の整え方（`docs/email-input.md`）

- 全角のアドレス（`ｔａｒｏ@example.com`）と句点のドメイン（`example。com`）は `email` の規則（`email:rfc,strict` も）を通る。`$middleware->web(append: \Miraqceed\Foundation\Mail\NormalizeEmailInput::class)` を入れて、検証の前にそろえる（`email`・入れ子・`_confirmation`。ほかの名前は `foundation.mail.input_fields` に足す）。ミドルウェアを通らない口（コマンド・外部のログイン・取り込み）は `EmailInput::normalize()` を呼ぶ。
- 保存する値（送る宛先）は `normalize()`、一意の列と探すとき（ログイン・再設定・外部の連携の照合）は `EmailInput::canonical()`（全体を小文字。Gmail だけ点と `+` を落とす）。Gmail のそろえ方をほかのドメインに広げない。送る宛先に `canonical()` の値を使わない。
- ログインのロック・`foundation.email_changes.email_key` は `Login\CanonicalEmailKey` にして、比べの列と範囲をそろえる。
- 使い捨てのメールを断るなら、登録・変更の欄に `new \Miraqceed\Foundation\Mail\NotDisposableEmail` を付け、一覧のファイルの道を `foundation.mail.disposable_domains` に書く（パッケージは一覧を持たない。公開の一覧を写して CI か schedule で取り直す）。一覧を自前で読み直す・`in_array` で毎回比べる形を書かない（1回だけ読み、下位のドメインも当てる）。ログインの欄には付けない。

### メールの中の安全と、要求の Host を信じないこと（`docs/mail-safety.md`）

- 訪問者が書いた文（名前・会社名など）をメールの Markdown に入れるときは、`Miraqceed\Foundation\Mail\MailText::escape()` に、つないだ全体を渡す（`MailText::escape($user->name.' さん')`）。`{{ $name }}` のまま入れない。`Markdown::withSecuredEncoding()` だけに頼らない（12.15 からで、12.70・13.36 より前は `view:cache` で外れる）。打ち消しを `\` で書かない（text の版に残る）。
- メールアドレスの確認が済むまで、利用者が書いた名前をメールに出さない（素の URL は、受け手のメールの道具がリンクにする）。
- 再設定・確認のリンクを要求の Host から作らない。`foundation.mail.links_from_app_url` を真にする（既定は無効）。全体に `URL::forceRootUrl()` を掛けない。
- `bootstrap/app.php` で `$middleware->trustHosts()` を足し、`trustProxies` の `headers:` から `X-Forwarded-Host` を外す（`Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT`）。`'*'` と既定の見出しでは、訪問者が直に送った `X-Forwarded-Host` も信じる。
- 自動のメール（通知・再設定・確認）には `withSymfonyMessage(fn (Email $email) => \Miraqceed\Foundation\Mail\AutomatedMessage::mark($email, '通知の id'))` で `Auto-Submitted` と、送り直しても同じ `Message-ID`（`APP_URL` のホスト）を付ける。id に乱数・時刻を入れない。人に宛てたメールを送らないアプリは `foundation.mail.auto_submitted` を真にして、すべてのメールに `Auto-Submitted` を足す。
- 送信元のドメインの SPF・DMARC（DKIM は `foundation.deploy_check.dkim_selector`）は `foundation:deploy-check` の `mail_domain` が DNS を引いて見る。DNS を引けない・遅い環境では `foundation.deploy_check.skip` に `mail_domain` を足す。

### 文の中の秘密を伏せる（`docs/redaction.md`）

- 利用者が貼った文・AI への問い・外のコマンドや API の誤りの文を、ログ・DB・外の AI に残す前に `Miraqceed\Foundation\Redaction\Redactor`（`app(Redactor::class)`）の `text()`・`values()`・`truncate()` を通す。秘密を見つける正規表現を自前で書かない。
- 字数で切って残すときは `truncate($text, $maxChars)` を使う。切ってから伏せない（切れ目に秘密の欠片が残る）。知らない形の入れ子は、知っている鍵だけに写してから `values()` に渡す（キーは伏せない）。
- **漏れを無くす部品ではない。** 形で見つけられる鍵・トークン・パスワード・メールアドレス・電話番号だけを伏せる。人の名前・顧客の名前・IP アドレスは別に扱う。壊れた UTF-8 は `UnreadableText` で止まる（残したい口では `mb_scrub()` してから渡す）。

### ログインのロック（`docs/login.md`）

- 失敗の数えを自前で作らない。`LoginLock` を使う。Fortify では次の3つが**すべて**要る（1つでも欠けると回数の制限が消えるか二重になる）：①`config/fortify.php` の `limiters` の `login` を `null`、②`Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline())`、③`Fortify::authenticateUsing(...)` に `AuthenticateWithLoginLock` を渡す。
- **逆プロキシ（ロードバランサー・Caddy・nginx・Cloudflare）の後ろでは、`bootstrap/app.php` の `$middleware->trustProxies(at: …)` が必須。** 設定しないと全員が同じ IP（プロキシの IP）になり、接続元の枠がサイト全体のロックになる。`at:` には前段のプロキシの IP・範囲を書き、`'*'` にしない（Laravel 13.20.0 から、`'*'` は訪問者が送った `X-Forwarded-For` を信じ、偽の IP で接続元の枠を避けられる）。前段のアドレスが分からないときの書き方と限界は `docs/login.md`。

@verbatim
<code-snippet name="Fortify に組み込む（FortifyServiceProvider の boot）" lang="php">
use Laravel\Fortify\Fortify;
use Miraqceed\Foundation\Login\Fortify\AuthenticateWithLoginLock;
use Miraqceed\Foundation\Login\LoginLock;
use Miraqceed\Foundation\Login\NormalizedEmailKey;

// config/fortify.php には 'limiters' => ['login' => null] も書く
Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline());
Fortify::authenticateUsing(new AuthenticateWithLoginLock(new LoginLock(new NormalizedEmailKey)));
</code-snippet>
@endverbatim

### 試みの数え（`docs/attempts.md`）

- ログイン以外の照合（確認コード・使い捨ての合言葉・現在のパスワード・カードの登録）の失敗を数えるときは、`Miraqceed\Foundation\Attempts\Attempts` の `attempt()`（窓のある数え）か `attemptConsecutive()`（続けた失敗）で照合を包む。数えを自前で作らない（見てから照合の後に足す形は、並んだ要求が通り抜ける）。
- 入力と権限の確かめを通った後に照合を包む。経路の手前（中間処理）で数えない。鍵は `AttemptKey::for(名前, ...値)` で作る（`APP_KEY` の HMAC）。
- 数え方：本人の枠は `Counting::FailuresOwn`、接続元など共有の枠は `Counting::FailuresShared`、お金の掛かる試みは `Counting::Every`。確認コード（TOTP など）は `attemptConsecutive()`（時間で消えない数えとロック）で数える。
- 回数の制限には `database`・`redis` などの原子的なキャッシュを使う（`file` では守りが弱まり、`array` では効かない）。アプリの `DB::transaction` の中で呼ばない（同じ接続の `database` のキャッシュだと、巻き戻しで数えも巻き戻る）。`foundation.attempts.bypass` は診断の間だけ。`production` では効かない。
- 頼まれるたびにメールを送る口（再設定の申請・確認の再送・変更の頼み・資料の請求）は、すべての頼みを `Counting::Every` で、宛先（`EmailInput::canonical()`）ごとと接続元ごとに数える。`throttle:` で数えない。再設定の申請（`Every`）と設定（トークンの照合の失敗、`FailuresShared`）は名前を分ける。数えは Turnstile・入力の検証・権限の確かめの後。

### Turnstile（`docs/turnstile.md`）

- Cloudflare Turnstile の確かめを自前で書かない。フォームは `'cf-turnstile-response' => ['required', new \Miraqceed\Foundation\Turnstile\TurnstilePassed]`、規則を足せない経路は `->middleware('foundation.turnstile')`、Fortify の経路は `VerifyTurnstileOnNamedRoutes` を web のグループに入れて `foundation.turnstile.routes` に名前を書く。鍵は `config/services.php` の `turnstile.site_key`・`secret_key`。
- 確かめられないとき（秘密がない・届かない）は断る。手元・試しで外す切り替えを作らない：手元は Cloudflare のテスト用の鍵、試しは `Http::fake(['challenges.cloudflare.com/*' => …])`。本番のテスト用の鍵は `foundation:deploy-check` の `turnstile` が `FAIL` にする。
- 訪問者の IP は既定で送らない。`foundation.turnstile.send_ip` を真にするなら `trustProxies` を書く。試みの数えは Turnstile の確かめを通った後。

### 二段階の認証（`docs/two-factor.md`）

- 認証アプリの確認コード（TOTP）を自前で書かない。手書きの認証は `Miraqceed\Foundation\TwoFactor\TwoFactor`（`confirm()`・`verify()`・`pass()`・`passed()`・`reset()`）、Fortify は容器の `Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider` を `FortifyTwoFactorProvider` に替える（`fortify.limiters.two-factor` は `null`）。
- 列は `vendor:publish --tag=foundation-two-factor-migrations` で足す。使い回しは条件つきの UPDATE で守る（確かめた後に読んで書く2段にしない）。登録に使ったコードの刻みを残す。間違いは続けた失敗で数える。秘密の鍵を平文で置かない。
- 鍵と QR を含む応答には `Cache-Control: no-store` を付ける。ロックの出来事 `TwoFactorLocked` の聞き手で、運営に知らせる。

### アカウントの乗っ取りの守り（`docs/accounts.md`）

- パスワードを変えた後は `app(\Miraqceed\Foundation\Accounts\PasswordSessions::class)->afterChange($request, $user)`、再設定の後は `afterReset($user)` を呼ぶ（同じ要求の中で。キューに載せない）。Fortify は `EndSessionsAfterChange`（`PasswordUpdatedViaController`）・`EndSessionsAfterReset`（`PasswordReset`）を `Event::listen` で登録する。パッケージは登録しない。
- パスワードを変えた後に `Auth::logoutOtherDevices()` を呼ばない（ハッシュの計算と書き込みが2回）。ほかの端末を締め出すには、`SESSION_DRIVER=database` か、web のグループの `auth.session`（`$middleware->authenticateSessions()`）が要る。`foundation:deploy-check` の `password_sessions` が見る。
- メールアドレスの変更を、その場で書かない（Fortify・Breeze の雛形のまま使わない）。`foundation.email_changes.enabled` を真にして `migrate`、`app(\Miraqceed\Foundation\Accounts\EmailChanges::class)` の `request()`（頼む口に `password.confirm` と回数の数え）→ 新しいアドレスへのリンクの `confirm($id, $token, $request->user())`（経路に `auth`。頼んだ本人のログインの中だけ）→ 古いアドレスへの戻すリンクの `undo()`（戻したら `Auth::logout()` と `session()->invalidate()`）。リンクは GET で表示だけ、POST で動かす。
- 登録・変更の検証に `new NotHeldEmail`（変更は `new NotHeldEmail($request->user())`）を足す。API キー・外部の連携は `AccountLockdown` を `foundation.email_changes.lockdown` に渡して戻すときに消す。パスワードの再設定・変更の後は `cancelPending($user)`。
- アドレスの変更は、管理者の操作も含めて `EmailChanges` を通す（直に書くと、それまでの戻すリンクが `changed` になり、持ち主が戻せない）。トークンをログ・flash に入れない。`model:prune` で `Miraqceed\Foundation\Accounts\EmailChange` を指す。
- 確認のリンクは Laravel の既定（`VerifyEmail`・`EmailVerificationRequest`）を使い、経路から `auth` を外さない。自前で書くなら、ログイン中の本人だけを確認済みにし、`APP_KEY` から用途の名前を入れて作るアドレスの鍵つきの指紋（`hash_hmac`）をリンクに入れて今のアドレスと比べる（`getPreviousKeys()` の前の鍵でも比べる）。戻り先（`url.intended`）を自前で持つなら、だれのリンクかと一緒に持つ。
- メールアドレスは、決めた1つの形にそろえて保存し、そろえて探す（登録・変更・ログイン・再設定・外部の連携のすべてで）。一意の索引は整えた値の列に付ける。
- 再設定のときにアドレスが未確認だったアカウントは、他人が先に作ったもの（外部の連携・API キー・二段階の認証・変更の頼み）も消す（`AccountLockdown::lockdown()`・`cancelPending()`）。未確認だったか（`$wasUnverified`）は、再設定の処理（`markEmailAsVerified()` を含む）より前に読む。外部の連携は、確認済みのアドレスにだけ付ける。

### 1回だけ使える合言葉（`docs/one-time-codes.md`）

- 一度だけ渡したい秘密（機械にトークンを受け取らせる合言葉・1回限りの入場券・確認のリンクの秘密）を自前で書かない。`Miraqceed\Foundation\OneTimeCodes\OneTimeCodes` の `issue()`・`claim()`・`revoke()` を使う。種類は `new CodeKind(名前, 頭の印, 効く秒数)`。`foundation.one_time_codes.enabled` を真にして `php artisan migrate`（表はアプリの既定の接続に置く）。
- 受け取れなければ `claim()` は理由を問わず `null`。受け取った後の処理（トークンを出すなど）は `claim()` の3つ目の引数で渡し、同じトランザクションの中で動かす（投げたら巻き戻り、合言葉はまた受け取れる。巻き戻るのは同じ接続の DB の書き込みだけなので、キュー・メールなど外への作用は `DB::afterCommit()` で後に回す）。戻り値は `$claimed->result`。読んでから印を書く2段の形を自前で書かない（同じ合言葉が同時に2回通る）。
- 受け取りの口は `Attempts::attempt()` で、接続元ごとの共有の枠（`Counting::FailuresShared`）で包む。合言葉は POST の本文で受け、URL に入れない・GET で消費しない。答えには `Cache-Control: no-store` を付け、断りの文は1つにする。
- 平文の合言葉を、リダイレクトと flash・ログ・監査の記録に入れない。繰り返し使う API のトークンは Sanctum を使う（この部品で作らない）。
- 古い行は `model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\OneTimeCodes\OneTimeCode` を指す（`foundation.one_time_codes.prune_days`、既定 7）。

### 知らせを1回だけ送る（`docs/once-notices.md`）

- 条件に達したときに送る知らせ（上限の80%・猶予の終わり・試用の終わり）の「送ったか」を、アプリの列で自前で書かない。`Miraqceed\Foundation\Notices\OnceNotices::send($key, fn () => …, keepUntil: …)` を使う。`foundation.notices.enabled` を真にして `php artisan migrate`。
- 鍵には回（期間・上限の値）と id を入れ、メールアドレス・名前を入れない。送れなければ印を戻し（`NoticeResult::Failed`、投げない）、次の確かめが送り直すので、確かめは schedule で繰り返し流す。二重が困る送り先だけ `keepOnFailure: true`。条件が戻ったら `forget($key)`。
- トランザクションの中で呼ぶと `NoticeResult::Deferred`（取る・送るは確定の後。結果は返らない）。
- キューで送るときは、確かめで `claim()` し、ジョブは送る直前に `$claim->holds()` が偽なら送らない。送れたら `sent()`、投げたら `release()`。取り直しの分（`claimMinutes:`・`foundation.notices.claim_minutes`）はキューの待ち＋`retry_after` より長くする。
- 古い印は `model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\Notices\Notice` を指す。`keepUntil` は回が終わった後にする（印が先に消えると、同じ回をもう一度送る）。

### 消す決まりの台帳と、台帳からの消去（`docs/retention.md`）

- 個人の情報を持つアプリは、DB のすべての表の消し方を1つのクラス（`Miraqceed\Foundation\Retention\DataMap`）に書き、`foundation.retention.map` にクラスの名前を書く。表ごとに `erasedWith`・`anonymizedWith`（すべての列を `erase` か `keep` に分け、理由を書く）・`prunedBy`・`prunedByCommand`・`kept`・`handledByApp` のどれかを書く。退会の消去の処理を、表を並べて自前で書かない。
- 試しに `DataMapAssertions::assertCoversSchema()` と `DataMapAssertions::assertScheduled()` を置く（表・列を足した PR で、台帳と schedule の書き忘れが落ちる）。Laravel の表（`failed_jobs`・`sessions` など）の消し方もアプリが書く。パッケージのモデルの `model:prune` は `--model` で渡す。
- 退会・解約したアカウントは `app(\Miraqceed\Foundation\Retention\Erasure::class)->erase('user', $id, when: fn (object $user) => …)` で消す。`when` には、握って読み直した行で消してよいかを書く（消す前に読んだ値で判断しない）。日次のループでは1件ずつ `try` で捕まえる。監査の記録は出来事 `OwnerErased`（id と数だけ）で書く。
- 本番は `foundation:deploy-check` の `retention_schedule` が、期限の消し方が schedule にあるかを確かめる。

### 生の IP を残さない（`docs/privacy.md`）

- 生の IP を保存しないと決めたアプリは、`foundation.privacy.sessions_without_ip` を真にする（`database` のセッションは、ログインしていない訪問者の IP も `sessions.ip_address` に書く）。driver の名前は `database` のまま。自前で `Session::extend('database', …)` を書かない。有効にすると、`ip_address` を見せる画面（ほかのブラウザのセッションの一覧など）で IP の欄が空になる。
- 回数の制限の鍵に `$request->ip()` をそのまま渡さない（`Limit::by($request->ip())` は秘密のない md5、数だけの `throttle:60,1` は sha1 でキャッシュの表に残り、IP に戻せる）。`AttemptKey::for('contact', IpBucket::of($request))` で鍵を作るか、`foundation.privacy.keyed_rate_limiter` を真にする。切り替えた時点で、ログインのロックを含む数えが一度空になるので、人の少ない時間に切り替える。
- DB に残す IP の値（同じ回線の数えなど）は `\Miraqceed\Foundation\Privacy\IpHash::of($ip)`（IPv6 を /64 で数えるなら `IpHash::ofBucket($request)`）。鍵は `APP_KEY` と別の `FOUNDATION_IP_HASH_KEY`（32字以上）。秘密のない `sha256($ip)` で残さない。鍵を入れ替えると前の値と比べられなくなる。
- IP を受ける口の試しに `RawIp::assertNotStored('203.0.113.7')` を置く（すべての表の文字の列に、その IP の書き方が残っていれば落ちる）。
- 本番は `foundation:deploy-check` の `session_ip`・`limiter_keys`・`ip_hash_key` が見る。

### 住所まわり（日本向け）（`docs/japan-address.md`）

- 郵便番号から住所を引くのに、外の API に問い合わせない（利用者の入れた番号を第三者に送らない）。`app(\Miraqceed\Foundation\Japan\PostalAddressBook::class)->lookup($input)` を使う。候補が複数なら画面で選ばせる。
- 一覧はパッケージに入っていない。`php artisan foundation:postal-codes` でアプリの倉庫に作り、毎月作り直して PR にする（`--check` で今の日本郵便のデータと同じかを確かめる）。置き場所（`foundation.japan.postal_codes.path`）は一覧だけのディレクトリにする。古さは `foundation:deploy-check` の `postal_codes` が見る。
- 日本郵便のデータを自前で読まない（町域の注記・括弧・高層ビルの階・事業所の番地の後ろの会社名を住所の欄に入れてしまう）。
- 電話番号を桁の数で区切らない。`PhoneNumber::format()`（入力の区切り・番号の種類・市外局番の表）で区切った形を保存し、`Rules\JapanesePhoneNumber`・`Rules\JapanesePostalCode` で断る（理由 `shape`・`grouping` で文を分ける）。
- 市外局番の表を写して配るときは、出典と「加工して作成」の記載を残す。

### DB の接続の時間帯（`docs/database-timezone.md`）

- `config/database.php` の pgsql・mysql の接続に `timezone` を書き、`app.timezone` と同じ値にする。PostgreSQL は時間帯の名前（`Asia/Tokyo`）で書く（`+09:00` と書くと 9 時間西と読まれる）。MySQL を時差（`+09:00`）で書いてよいのは、夏時間の無い時間帯（`Asia/Tokyo`・`UTC` など）だけ。夏時間のある時間帯は名前で書き、MySQL に時間帯の表を読み込む（時差で書くと、一年の片方の季節だけずれる）。
- 配置の台本や CI で `php artisan foundation:db-timezone` を流す（ずれていれば終わりの値 1、つながらないなど確かめられなければ 2）。

### DB のロック（`docs/database-locks.md`）

- 行のロックで並べられない数え（アカウントをまたぐ同じ回線の数など）は、`app(\Miraqceed\Foundation\Database\NamedLock::class)->run($name, waitSeconds: 10, work: fn () => DB::transaction(…))` で並べる。`GET_LOCK`・`pg_advisory_lock` を自前で書かない（放すのに失敗しても投げない・名前は DB ごと・サーバー全体の名前の64字の上限・確定の後に放す）。取れなければ `LockNotAcquired` を断りにする。
- `NamedLock::run()` はトランザクションの外で呼ぶ（中では `LogicException`。中で取ると savepoint になり、外の確定より先に放す）。ロックの順は「名前つきのロック → 行（id の順）」に固定する。ロックの中に外への HTTP・長い処理を入れない（途中で接続が切れると、Laravel がつなぎ直してロックなしで続く）。
- 行のロックを待つ時間は `app(\Miraqceed\Foundation\Database\LockWait::class)->atMost(10, fn () => …)` で限る。`SET SESSION innodb_lock_wait_timeout`・`SET lock_timeout` を自前で書かない（戻し忘れはキューのワーカーの後の仕事に効く。`SET SESSION` は巻き戻しで戻らない）。
- 「トランザクションの外で呼ぶ」の確かめは `Transactions::assertOutside('理由')`（試しの `RefreshDatabase` の包みは外に数える）。`DB::transactionLevel()` と設定の段数で比べない。
- MySQL の待ちの誤り（1205）を捕まえて同じトランザクションを続けない（文だけが巻き戻り、前半が確定する）。PostgreSQL の待ちの誤り（55P03）は Laravel の `DB::transaction($cb, attempts:)` がやり直さないので、要るなら `LockErrors::isLockWaitTimeout($e)` を見て一番外でやり直す。
- `forceIndex()` は DELETE・UPDATE で黙って消える。主キーを `forceIndex()` つきの SELECT で選んでから、主キーで消す・更新する。
- SQLite では `NamedLock` はロックを取らずに動き、`LockWait` は何もしない。複数のプロセスが同時に書く使い方では MySQL か PostgreSQL を使う。
- 「ほかの要求が握っている間は待って止まる」の試しは `\Miraqceed\Foundation\Testing\HeldLocks::row($table, $id, fn () => …)`・`HeldLocks::name($name, fn () => …)`。握る行はコミットした行（`RefreshDatabase` でなく `DatabaseTruncation` の試し）。

### 本番の設定の点検（`docs/deploy-check.md`）

- 配置の台本の最後（`migrate`・`up` の後）と CI で `php artisan foundation:deploy-check` を流す（失敗があれば終わりの値 1。機械向けは `--json`、公開の直前は `--strict`）。点検を自前で書き直さない。
- サービス固有の調べ（決済の鍵・運営者の表示など）は `Miraqceed\Foundation\DeployCheck\Check` を実装し、`config/foundation.php` の `deploy_check.checks` に足す。id は変えない。調べは読むだけにする。
- `label()`・`detail` に設定の値（鍵・パスワード・接続先・URL）を入れない。何が違うかを値なしで書く。
- 公開の前に要らない値は `Result::later()`。値が入っていて形が違えば、公開の前でも `Result::fail()`。
- 逆プロキシの後ろで訪問者の本当の IP が要るアプリは、`deploy_check.proxies` を `'required'` にする。

### 失敗の記録と要求の id（`docs/failures.md`）

- 失敗を記録してから続ける `catch` の中では、`report()` でなく `app(\Miraqceed\Foundation\Failures\FailureReporter::class)->report($e)` を使う。`report()` はログのファイルを開けないと投げ、本筋ごと止める。`FailureReporter` は投げず、例外を書き換えない。
- 同じ失敗が続く所は鍵を渡して間引く（`->report($e, 'checkout.store:'.$e::class, 60)`）。目印は storage のファイルで、DB・キャッシュに触れない。鍵は失敗の種類にし、IP・メールアドレス・id を入れない。`Cache::add` や `$exceptions->throttle()` で自前に間引かない（DB の障害で間引きも落ちる）。
- このパッケージは例外の文を伏せない。`mask_bindings_in_exception_messages` を真にしても、一意の違反の文（`Duplicate entry`・`DETAIL`）には値が残る。IP を入れる列の失敗は `FailureReporter::redactIp($e->getMessage(), $ip)` で伏せた文で例外を作り直して記録する。メールアドレスは `Redactor` で伏せる。`zend.exception_ignore_args` は Web と CLI の両方で On にする。
- 要求の id は `bootstrap/app.php` で `$middleware->prepend(\Miraqceed\Foundation\RequestId\AssignRequestId::class)`。利用者の `X-Request-Id` を信じて自前に作らない。外の事業者への HTTP には id を付けない（自分のサービスへは `RequestId::headers()`）。
- 「記録が壊れても本筋は止まらない」の試しは `ReportFailure::simulate()`（`Exceptions::fake()` の中では使えない）。

### 子プロセスの環境変数（`docs/child-processes.md`）

- 外のコマンドを子プロセスで動かすときは、`Process::env(\Miraqceed\Foundation\Support\ChildEnvironment::only(['PATH', …]))` で渡す名前を絞る。何もしないと、子は `.env` の秘密（`DB_PASSWORD`・`APP_KEY` など）を受け継ぐ。
- 標準入力に書く子は、動かす前に `ExecutableFinder` でコマンドがあるかを確かめる。子の誤りの文をログに残すときは、`Redactor` を通す。

### 自分で書くときの注意

- 値の形を正規表現で確かめるときは、`$` に `D` を付けるか `\z` を使う（`$` は後ろの改行1つの前でも合う）。
- `/u` の `\p{Han}` は、句読点（`、` `。` `「」`）も含む。漢字だけなら `\p{sc=Han}`（PCRE2 10.40 以上。PHP 8.3 以降に同梱の PCRE2 は満たす。OS の PCRE2 を使うビルドでは `PCRE_VERSION` で確かめる）。
- セッションを DB に暗号にせずに置くとき（`SESSION_DRIVER=database`・`SESSION_ENCRYPT=false`）、名前や本文などの機微な入力を `withInput()`・`with()` でセッションに入れない。入れるなら `SESSION_ENCRYPT=true` にする。検証の誤りで戻すときの `dontFlash` に機微な欄を足す。
- パスワードを変えたら、その利用者のほかのセッションを閉じ、今のセッションは残す。後始末は `PasswordSessions`（上の「アカウントの乗っ取りの守り」）を使い、自前で書かない。自前で書くなら：`Auth::logoutOtherDevices()` は渡した平文でパスワードを再ハッシュして保存するだけで、ほかのセッションを実際に閉じるのは `auth.session`（`AuthenticateSession`）のミドルウェア。`database` のセッションの `user_id` の行を消して閉じるなら、`remember_token` も回す（行を消すだけでは「ログインしたままにする」のクッキーで入り直せる）。今のセッションは番号を作り直す。
- 外を呼ぶ口（外の API・CLI）の偽物は、試しの `createApplication()` で差す（`setUp` で差すと、試しの途中で `refreshApplication` したときに本物に戻る）。
- コマンドのオプションで、値の無い指定（`--name=`）を「全部」と読まない。値が要るオプションは、空なら断る。
- 設定の書き違い（知らない値・範囲の外）を、黙って既定に寄せない。分かる文で止めるか、管理の画面・状態のコマンドにはっきり出す。

### 要求の本文の上限と、中間処理の並び順（`docs/http.md`）

- 数の上限のない並びを受け取る API には `->middleware('foundation.body-limit:65536')`（バイト）。回数の制限より前に動く（パッケージが優先の表に入れる）。サイト全体の上限は、全体の先頭に `\Miraqceed\Foundation\Http\LimitBodySize::class.':10485760'`（クラス名で。`SecurityHeaders` の内側）。
- JSON の本文は Laravel が要求を作る時点で解くので、どの中間処理でも解く前には断れない。解かせないのは Web サーバーの上限（`client_max_body_size` など）と `post_max_size` だけ。
- 自前の認証は `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` を実装する（表にない中間処理は並べ替えで動かず、`throttle` の後に書くと利用者を知らずに数える）。運営の入口・入口の 404 は `prependToPriorityList` で `ThrottleRequests`・`EncryptCookies` の前に入れる。
- 並びは試しで固定する：`\Miraqceed\Foundation\Testing\RouteMiddleware::assertBefore('GET', '/api/…', AuthenticatesRequests::class, ThrottleRequests::class)`。

### 日本語の入力の誤りの文（`docs/validation-ja.md`）

- `APP_LOCALE=ja` のアプリは `php artisan vendor:publish --tag=foundation-lang-ja` で Laravel の規則・認証・再設定・ページ送りの日本語の文を `lang/ja` に写す（`lang:publish` は英語だけ）。既にあるファイルは上書きしない。使う規則の文だけを自分で書かない（後で使い始めた規則が英語になる）。
- 欄の名前は、どの画面でも同じ呼び名の欄だけを `attributes` に置く。`name` のように画面で呼び名が違う欄は、確かめる所（`validate()` の3つ目の引数・Form Request の `attributes()`）で渡す。
- 試しで確かめる：`$this->assertSame([], \Miraqceed\Foundation\Testing\Translations::missing('ja'))` と、欄を空にして送った応答に `Translations::assertNoEnglish($response)`（「current passwordを入力してください。」を見つける）。

### CSP・セキュリティヘッダー（`docs/security.md`）

- `foundation.security.enabled` を真にし、ミドルウェアを**自分で**グローバルの先頭に足す：`$middleware->prepend(\Miraqceed\Foundation\Security\SecurityHeaders::class)`。`web` のグループに足すと、ルートに合わない 404・メンテナンス中の 503・大きすぎる POST の 413 に付かない。グローバルでは別名 `foundation.security-headers` は使えない（クラス名で書く）。
- グローバルに置くと、ルートごとに `withoutMiddleware()` で外せない。埋め込ませたいページは、そのパスを別の区分にして `frame-ancestors` に許す元を書く。
- `'unsafe-inline'` を足して済ませない。外の元は `foundation.security.areas` に足す。インラインの `<script>`・`<style>` には `Nonce::get()` の nonce を付ける。
- Laravel 12・13 の Vue・React の starter kit は、`resources/views/app.blade.php` にインラインの `<script>`（ダークモード）と `<style>` を持つ。どちらにも `nonce="@{{ Vite::cspNonce() }}"` を付ける（付けないと CSP で止まる）。
- `X-Frame-Options` は区分の `frame-ancestors` から決まる（`'none'` → DENY、`'self'` だけ → SAMEORIGIN、ほかの元を含む → 付けない）。iframe を許すときは `X-Frame-Options` を自分で付けず、`frame-ancestors` を直す。
- 違反の報告の口（`report_route`）を `web` のグループに入れない。報告の口の回数の制限（接続元ごと）も、逆プロキシの後ろでは `trustProxies` が前提。

### 稼働の確かめ（`docs/health.md`）

- `withRouting()` の `health: '/up'` を使わず、`then: fn () => Health::route('/up')`（`Miraqceed\Foundation\Health\Health`）にする。既定の `/up` は外の CDN のスクリプトを読む HTML を返し、デバッグ中は原因を画面に出す。この口は text/plain の `OK`（200）／`NG`（500）だけを返す。
- `health:` を消し忘れない。別の道に残すと、本番でない環境（試しの中を含む）では `Health::route()` が `LogicException` で止める。本番では止めないので、試しで見つける。`Health::route()` は1つの道にだけ使う。`routes/web.php` に書かない（セッションができる）。
- DB まで確かめるときだけ `foundation.health.checks` に `'database'` を書く（既定は見ない）。ほかのものは `DiagnosingHealth` の聞き手で足す。

### 見張りと知らせ（`docs/monitoring.md`）

- `foundation.monitoring.enabled` を真にして `php artisan migrate`。schedule の処理には `->watched('名前', 許す古さ（分）)`。`->environments()` で絞った処理はその環境の外では見張らないが、`->when()`・`->skip()` で止まる処理は見張る（条件で止まる処理に `->watched()` を付けない）。schedule の外の処理は終わりに `php artisan foundation:record 名前` を呼び、`foundation.monitoring.external` に書く。
- 知らせの送り先は `foundation.monitoring.alerts.channels`（用意した5つから選ぶ）。コンテナの標準エラーを集めて運ぶ環境では `log` を使い、`monitoring.alerts.log.channel` を `stderr` にする。`monitoring.alerts.log.level`（既定 `error`）がチャネルの `LOG_LEVEL` より低いと、ログは捨てられるのに送れた扱いになる。
- `foundation:watch` の知らせの鍵は止まっている処理の名前の組ごと（`foundation.watch:` ＋ 名前を並べ替えて `,` でつないだものの sha1）。新しく止まった処理はすぐ知らせ、同じ組は間引く。間引きは `throttle_minutes` が10より大きいとき `throttle_minutes - 5` 分（既定で55分）。鍵を `foundation.watch` の1つと思って読まない。
- 知らせの表は自動では消えない。`model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\Monitoring\Alerts\Alert` を指す（`--model` なしでは `app/Models` しか見ない）。送れたか諦めて `monitoring.alerts.prune_days`（既定 90）日たった知らせが消える。
- **キャッシュのストアが `database` なら、`foundation:cache-prune` を schedule に入れる**（`Schedule::command('foundation:cache-prune')->hourly()`）。Laravel は期限切れの行を、その鍵がもう一度読まれたときにだけ消すので、回数の制限の行などが残り続ける。パッケージは自分では schedule に入れない。見張りが無効でも使える。
- `foundation:install` で、この節を `AGENTS.md`・`CLAUDE.md` に写せる。

### 共用サーバーで cron からキューを回す（`docs/queue.md`）

- 常駐の worker を置けない環境で `database` のキューを回すときは、`routes/console.php` で `\Miraqceed\Foundation\Queue\CronWorker::schedule(queues: ['mail', 'default'], maxTime: 20, timeout: 30)` を呼ぶ。`queue:work` の schedule を自前で書かない（ロックの分・`retry_after`・見張りの数がつながっている）。メールは先のキューに、重い仕事は別の worker に分ける。
- `timeout + 10 <= retry_after` にする（ジョブの `$timeout` も）。満たさないと、処理中のジョブを次の worker が取り直して2回動く。pcntl がないと `--timeout` は効かないので、ジョブの中の外への通信に短い timeout を付ける。
- 本番で `QUEUE_CONNECTION=sync` にしない。本番で `cache:clear` を流さない（`database` のキャッシュの回数の制限・ロックの数えが消える）。毎分の処理に `withoutOverlapping()` を付ける。
- 見張りが有効なら、`foundation:watch` がキューの溜まり（許す待ちは `monitoring.queues`、既定 10 分）・新しく失敗したジョブ・DB の大きさ（`monitoring.database.capacity_bytes` を決めたとき）も知らせる。画面には `QueueCheck` を使う。
- `foundation:deploy-check` の `queue_worker`・`queue_timeouts` を通す。

### AI の費用の上限と記録（`docs/ai.md`）

- AI は **Laravel AI SDK（`laravel/ai` ^1.4）で呼ぶ。各社の SDK や HTTP で直に呼ばない**（直に呼んだ分は数えられず、上限もかからない）。
- `foundation.ai.enabled` を真にして `php artisan migrate`。上限は `foundation.ai.daily_limit_yen`・`monthly_limit_yen`・`daily_call_limit`（空は上限なし、`0` はすべて止める）。金額の上限を使うなら `foundation.ai.prices` に単価（ドル／100万トークン）を書く。単価のないモデルは0円にせず、いちばん高い単価で数える。
- 上限に達すると `Miraqceed\Foundation\Ai\AiLimitReached` が投げられる。画面で捕まえて「今は AI を使えません」と出す。この例外は会社の切り替えを起こさない。キューのジョブでは `tries` の回数だけやり直す。
- 使った額を数えられないとき（単価の表が空・DB が読めないなど）は `foundation.ai.when_uncountable`（既定 `block`＝止める）に従う。
- 使用量の行は自動で消えない。`model:prune` を schedule に入れ、`--model` で `Miraqceed\Foundation\Ai\AiUsage` を指す（`foundation.ai.prune_days`、既定 90）。
- 知らせは `foundation.monitoring.enabled` が真のときだけ届く（日・月に1回）。画像・音声・埋め込みなどは数えず止めない。

### 利用者の URL をサーバーから開く（`docs/ssrf.md`）

- このパッケージは守りを持たない。PHP 8.4 以上のアプリは `cboxdk/laravel-ssrf`（^1.5）を入れ、利用者が入れた URL（Webhook の送り先・画像や記事の取り込みなど）は `Http::ssrf()->get($url)` で開く。保存する前の確かめは検証の規則 `new \Cbox\Ssrf\Rules\PublicUrl`。
- PHP 8.3 のアプリでは `cboxdk/laravel-ssrf` を入れられない。利用者の URL をサーバーから開く機能を作る前に、PHP を 8.4 へ上げる。上げられない間は、その機能を作らず、作る人（頼んだ人）に知らせる。自前の守りで代わりにしない。
- `Http::get($url)` で直に開かない。名前を引いて確かめてから `Http::` で開く自前の守りを書かない（つなぐときに名前を引き直すので、DNS の書き換えですり抜ける）。
- `guzzlehttp/guzzle` は 7.15.2 以上（8 系は 8.0.1 以上）にする（それより前はホストの読み方の食い違いで確かめをすり抜ける）。
- `HTTP_PROXY` などのプロキシを通すと、つなぐ先の固定が効かない。出口のファイアウォールで社内のアドレスと `169.254.169.254` を止めるのが本筋。
- `assertSafeRedirect()` は名前を引かず、`127.1` のような書き方を見落とす。サーバーから開く URL の確かめに使わない。
