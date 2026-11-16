# 変更の記録

## 未公開

- コマンド `foundation:deploy-check` と `DeployCheck\Check`：本番の設定を1か所で調べ、違う所の id と説明を出して終わりの値 1 で終わる（`--json`・`--strict`）。既定の調べは15（`APP_ENV`・`APP_DEBUG`・`APP_URL`・暗号の方式に合う `APP_KEY`・Boost・`SESSION_SECURE_COOKIE`・キャッシュとセッションの種類・届くメールの設定・`view:cache` で外れるメールの守り（直っていない版の Laravel だけ）・`public/hot`・Vite の manifest・書き込み・残ったマイグレーション・`foundation.attempts.bypass`・信頼するプロキシ）。アプリは `foundation.deploy_check.checks` に調べのクラスを足す。調べが投げてもその行だけを失敗にし、例外のクラス名だけを出す。設定の値は出さない。流したときだけ働く（schedule に入らない。書き込まない）。`docs/deploy-check.md`
- メールの中の安全：訪問者が書いた文をメールの Markdown の中で文字のまま出す `Mail\MailText::escape()`（HTML と Markdown の特別な字を数値の文字参照にし、改行を空白にする。HTML の版でリンク・画像・見出しにならず、text の版では書いたとおりに読める。Laravel の版・`view:cache`・`withSecuredEncoding()` によらない）と、再設定・確認のメールのリンクを要求の Host でなく `APP_URL` から作る設定 `foundation.mail.links_from_app_url`（既定は false。確認のリンクは絶対の URL で署名し、`signed` の経路がそのまま通る。全体の URL の状態は変えず、アプリの `createUrlUsing` が勝つ）。Host を偽った要求を `trustHosts()` で断り、`trustProxies` の `headers:` から `X-Forwarded-Host` を外す書き方を説明書と指針に書いた。**入れただけでは変わらない。** `docs/mail-safety.md`

## 0.8.1

- `TwoFactor`：並んだ要求の正しいコードが通ってロックを空にした後、ロックの秒数の間にまたロックしても、出来事 `TwoFactorLocked` が出なかったのを直した（通ったときに、出来事を出した印も消す）。二段階の認証の試しの「間違ったコード」を、受けられる刻みのコードと重ならないように作る（乱数の鍵に決め打ちのコードを当てていたので、まれに落ちた）

## 0.8.0

**入れただけでは変わらない。** 1回だけ使える合言葉（`OneTimeCodes`）は、`foundation.one_time_codes.enabled` のときだけ表を作り、呼んだときだけ働く。稼働の確かめ（`Health\HealthCheck`）は、アプリが `/up` を替えたときだけ働く。**`trustProxies(at: '*')` にしているアプリは、説明書のとおりに前段のプロキシを `at:` に書く**（コードは変わらない）。

- 稼働の確かめ：Laravel の既定の `/up` に替える口 `Health\HealthCheck`（text/plain の `OK`（200）／`NG`（500）。外のスクリプトを読まず、デバッグ中も原因を出さない。`Cache-Control: no-store`）と、経路を登録する `Health::route('/up')`（web のグループの外・メンテナンス中も応答。`health:` の消し忘れと別の道への2回の登録は、本番でない環境で `LogicException`）。設定 `foundation.health.checks` に `'database'` で DB も確かめる（既定は見ない。知らない名前は `/up` の `NG`）。入れただけでは経路は増えない。`docs/health.md`
- `OneTimeCodes`：1回だけ使える合言葉（期限つき・SHA-256 だけを持つ）。種類 `CodeKind`（名前・頭の印・効く秒数）ごとに `issue()`・`claim()`・`revoke()`。受け取りは期限・使った・失効・種類を条件に入れた UPDATE 1つで、同時の要求でも1回だけ通す。受け取れなければ理由を問わず `null`（分離の水準を上げた接続で競り負けても `null`。行き詰まり・直列化の失敗は3回まで試し直す）。受け取った後の処理は同じトランザクションの中で動き、投げたら巻き戻る（戻り値は `ClaimedCode::$result`）。出し直すと、同じ種類・同じ対象の前の合言葉を失効させる（別々の対象に同時に出しても行き詰まらない）。種類と対象は字のとおりに比べる（MySQL の列は `utf8mb4_0900_bin`）。平文を受ける引数に `#[\SensitiveParameter]`。時刻は `dateTime` に UTC。形・作り方・ハッシュの助け `CodeFormat`。古い行は `model:prune` で `OneTimeCode` を指して消す（`foundation.one_time_codes.prune_days`、既定 7）。**入れただけでは変わらない**：表は `foundation.one_time_codes.enabled`（既定 false）が真のときだけ作り（`vendor:publish --tag=foundation-one-time-code-migrations` でも写せる）、部品は呼んだときだけ働く。`docs/one-time-codes.md`
- 説明書と指針：逆プロキシの後ろの `trustProxies(at: '*')` を勧めないように直した。Laravel 13.20.0 から `'*'` はすべてのアドレスを信頼し、`$request->ip()` が `X-Forwarded-For` の左端（前段が足す作りなら訪問者が送った値）になるので、偽の IP でログインのロックの接続元の枠と CSP の違反の報告の口の回数の制限を避けられる。**`'*'` にしているアプリは、前段のプロキシの IP・範囲を `at:` に書く**（前段が分からないときの `headers:` の書き方と、その限界も書いた）。コードは変わらない。`docs/login.md`・`docs/security.md`

## 0.7.0

**ログインのロックで現在のパスワードの確かめを使っているアプリは、同じ利用者が30分に10回間違えると、接続元を替えても確かめが止まるようになる。** CSP の違反の報告の口を有効にしているアプリは、版を上げた直後に、報告の口の1分の数えが一度空になる。ほかの新しいもの（`DatabaseTimezone`・`ChildEnvironment`・`prevent_stray_requests`）は、呼んだときか設定したときだけ働く。

- `Support\DatabaseTimezone` とコマンド `foundation:db-timezone`：DB の接続の時間帯が、アプリの時間帯とずれていないかを、今の時差で確かめる（PostgreSQL・MySQL）。ずれていればコマンドは終わりの値 1、つながらない・`--connection=` の値が空なら 2。出力に DB のホスト・ユーザー名・DB の名前を出さない（つながらないときの例外の文も出さない）。MySQL の接続を時差で書いてよいのは、夏時間の無い時間帯だけ（説明書）。自動では確かめない。`docs/database-timezone.md`
- `Support\ChildEnvironment::only()`：子プロセスに親の環境（`.env` の秘密）を受け継がせず、挙げた名前だけを渡す環境の配列。Laravel の `Process::env()`・Symfony の `Process` に渡す。`docs/child-processes.md`
- AI 向けの指針に、部品によらない「自分で書くときの注意」の節を足した（正規表現の `$`・`\p{Han}`・セッションへの機微な入力・パスワードを変えたときのセッション・試しの偽物の差し方・空の値のオプション・設定の書き違い）。`foundation:install` にも写る。
- ログインのロック：現在のパスワードの確かめ（`attemptPasswordConfirmation()`）に、利用者ごとの枠（`foundation.login.password_account_max`=10・`password_account_decay_seconds`=1800）を足した。これまでは利用者と接続元の組の枠だけで、接続元を替えれば試し続けられた。**同じ利用者が30分に10回間違えると、接続元を替えても確かめが `LoginLockedOut` で止まる**（ログインは止めない。利用者と接続元の組の枠は残る）。CSP の違反の報告の口：回数の数えの鍵を `APP_KEY` の HMAC にした（これまでは接続元の IP がそのまま鍵に入り、`database` のキャッシュの表に残った）。**鍵が変わるので、版を上げた直後に1分の窓の数えが一度空になる。** 古い鍵の行（IP 入り）は `database` のキャッシュに期限切れのまま残るので、`foundation:cache-prune` で消す。`APP_KEY` が空なら、報告の口は 500 になる。`docs/login.md`・`docs/security.md`
- テストの守り：設定 `foundation.test_safety.prevent_stray_requests`（既定は偽）を足した。真にすると、テストの中だけ `Http::preventStrayRequests()` を掛け、`Http::fake()` に当たらない外への要求を送る前に止める。テストの中かは DB の守りと同じ判定。**入れただけでは変わらない。** 説明書に、並列の試しでは元のテスト用 DB を先に作ること（ないと `Unknown database` で全部落ちる）を書いた。`docs/test-safety.md`

## 0.6.0

**入れただけでは変わらない。** 二段階の認証（`TwoFactor`）は、呼んだときと、Fortify の差し替えを容器に結び付けたときだけ働く。列を足すマイグレーションは、`vendor:publish` で写して使う。

- `TwoFactor`：認証アプリの確認コード（RFC 6238 の TOTP）。芯 `Totp`（依存なし。RFC のベクタと同じコード）、手書きの認証向けの `TwoFactor`（登録・確かめ・空にする・通った印。最後に受けた刻みを条件つきの UPDATE で書き、同じコードの同時の要求は1つだけ通す。登録に使ったコードの刻みも残す。秘密の鍵は暗号にして書く）、Fortify の確かめの差し替え `FortifyTwoFactorProvider`（使った刻みの印を `Cache::add` で立てる。印の鍵は鍵と刻みの HMAC）。間違いは試みの数えの続けた失敗で数え、ロックで出来事 `TwoFactorLocked` を出す。列は `vendor:publish --tag=foundation-two-factor-migrations` で足す。設定 `foundation.two_factor`。`docs/two-factor.md`
- 試みの数え：続けた失敗のロックが明けるまでの秒を読む `Attempts::lockedFor()`。`docs/attempts.md`

## 0.5.0

**ログインのロックを使っているアプリは、版を上げた直後に、ログインの失敗の数えが一度消える**（数えの鍵が `APP_KEY` の HMAC に変わるため）。`APP_KEY` が空のアプリは、ログインの数えで止まる。ほかの新しい部品（`Redaction`・`Attempts`）は、呼んだときだけ働く。

- ログインのロック：数えの鍵が無い窓の始まりに要求を一斉に並べると、上限より多く照合できた（0.1.0〜0.4.0。Laravel の `RateLimiter::increment` が、並んだ要求の足し算を書き直して消すことがあるため）。`Attempts` の数えに替えて直した。
- `Redaction\Redactor`：利用者が貼った文・外の誤りの文を、ログ・DB・外の AI に残す前に、形で見つけられる秘密（秘密鍵・AWS・GitHub・`sk-`・Slack のトークン・JWT・`password=` などの値・接続文字列のパスワード・メールアドレス・日本の電話番号）を印（既定 `[REDACTED]`）に替える。口は `text()`・`values()`（入れ子の配列）・`truncate()`（伏せてから字数で切る。出力は上限以下）。見つけたものは種類と場所だけを持つ。壊れた UTF-8 は `UnreadableText` で止める。設定 `foundation.redaction`（印・種類を絞る・形を足す）。漏れを無くすとは約束しない。呼んだときだけ働く。`docs/redaction.md`
- `Attempts\Attempts`：ログイン以外の照合の試みを、照合の前に1回分を足して数える口。窓のある数え `attempt()`（数え方は本人の枠・共有の枠・試みを全部の3つ）と、続けた失敗 `attemptConsecutive()`（時間で消えない数え・上限でロック・明けたら1回だけ照合）。鍵は `AttemptKey::for()`（`APP_KEY` の HMAC）。診断の間だけ数えずに照合する `foundation.attempts.bypass`（`production` では効かず、`report()` する）。`docs/attempts.md`
- ログインのロック：数えを `Attempts` の上に載せ直した。**数えの鍵が `APP_KEY` の HMAC に変わるので、版を上げた直後に、ログインの失敗の数えが一度消える。** 枠・上限・窓・成功したときの扱いは変わらない。`APP_KEY` が空なら、ログインの数えで `RuntimeException` になる。`LoginLockedOut` は `Attempts\TooManyAttempts` の子になった（`new LoginLockedOut(int)`・`$e->retryAfter`・文は変わらない）。`docs/login.md`

## 0.4.0

**入れただけでは変わらない。** 心拍（`heartbeat_url`）を設定しているアプリだけ、送れなかったときに送り直すようになる。`foundation:cache-prune` は schedule に自動では入らない。見張りの一覧は、Web の要求の中でも読めるようになった（初めて読むときに1回だけ Console Kernel を立ち上げる）。

- 見張りの `WatchList`（と `StallCheck::stalled()`）が、Web の要求（HTTP Kernel だけで立ち上がる）では schedule の `->watched()` を1つも見つけず、`StallCheck` がいつも空を返していた。初めて読むときに Console Kernel を立ち上げて schedule を読ませる（1回だけ。Console の中では今までどおり）。`docs/monitoring.md`
- 見張り：心拍（`heartbeat_url`）を、つながらない・5xx・429 のとき2秒あけて合わせて3回まで送るようにした（これまでは1回で諦めていた）。4xx は送り直さない。`database` のキャッシュの期限切れの行を消すコマンド `foundation:cache-prune` を足した（Laravel はその鍵がもう一度読まれたときにだけ消す）。1000件ずつ・1回60秒まで消し、消している間に書き直された行は残す。**パッケージは schedule に入れない**：キャッシュのストアが `database` のアプリは `Schedule::command('foundation:cache-prune')->hourly()` を足す。`docs/monitoring.md`

## 0.3.0

**入れただけでは変わらない。** 新しい部品は、呼んだときだけ働くか、既定で無効（`foundation.mail.drop_reserved_recipients`）。挙動が変わるのは、見張りの知らせをメールで送っているアプリだけ（下の「実在しないメールアドレス」の項）。CSP・セキュリティヘッダーは、説明書と指針の入れ方だけが変わった（コードは同じ）。SSRF は説明書と指針だけ（守りは別のパッケージを勧める）。

- `Monitoring\StallCheck::stalled()`：止まっている定期の処理を読むだけの口。`foundation:watch` と同じ判断（`foundation:watch` もこれを使う形にした。判断は変えていない）で、何も書かず知らせも送らない。見張りがまだ見ていない処理は止まっていない扱い。`docs/monitoring.md`
- CSP・セキュリティヘッダー：説明書と AI 向けの指針の入れ方を、`web` のグループからグローバルの先頭（`$middleware->prepend(SecurityHeaders::class)`）に変えた。`web` のグループでは、ルートに合わない 404・メンテナンス中の 503・大きすぎる POST の 413 に付かなかった。**コードは変わらない。** `web` のグループに入れているアプリは、グループから外してグローバルの先頭へ移す（両方に残っても見出しは1つ）。グローバルではルートごとに外せないので、埋め込ませたいページは区分の `frame-ancestors` で扱う。`docs/security.md`
- 実在しないメールアドレス：判定 `Mail\ReservedEmailDomains::isReserved()`（RFC 2606・RFC 6761 の名前と点のないドメイン。前後の空白・大文字・末尾の点・全角・句点をそろえてから比べる。DNS は引かない）、入力の検証の規則 `Mail\NotReservedEmailDomain`（文は `foundation::validation.reserved_email`、`vendor:publish --tag=foundation-lang` で上書き）、送る前に予約の宛先を To・Cc・Bcc から外し、残らなければ送信を取りやめる差し込み（既定で無効。`foundation.mail.drop_reserved_recipients`）。見張りの知らせのメールは、届かない宛先を外して残りへ送り、残りがなければ送れなかったと記録するようになった（これまでは送れたと記録していた）。`docs/reserved-email-domains.md`
- `Support\UnsignedInteger`：非負整数の和・積・10の累乗で割った切り上げ・比較を、PHP の整数の上限を超えても正確に計算する。値は非負の int または ASCII の数字列を受け取り、和・積・切り上げは正規化した数字列を返す。設定や追加の拡張は不要。`docs/integer-math.md`
- 説明書 `docs/ssrf.md` と AI 向けの指針の節を足した：利用者が入れた URL をサーバーから開くときは、別のパッケージ `cboxdk/laravel-ssrf`（^1.5、PHP 8.4 以上）の `Http::ssrf()` で開き、`guzzlehttp/guzzle` を 7.15.2 以上にする。このパッケージのコードは変わらない。

## 0.2.0

**入れただけでは変わらない。** 新しい部品は既定で無効（`foundation.ai.enabled`）。使うには `laravel/ai` ^1.4 が要る（`composer.json` の `suggest`。`<1.4 || >=2.0` は `conflict`）。

- AI の費用の上限と記録：Laravel AI SDK で呼ぶ AI に、アプリ全体の1日・1か月の金額と1日の回数の上限をかけ、上限以上なら送らずに `AiLimitReached` を投げる。呼び出しごとの使用量と原価を `foundation_ai_usages` に、日ごとの合計を `foundation_ai_totals` に残す。単価の表（`foundation.ai.prices`）に既定値はなく、単価のないモデルは0円にせず、いちばん高い単価で数える。使った額を数えられないときは `foundation.ai.when_uncountable`（既定 `block`）に従う。上限に達したことは、見張りが有効なら運営へ知らせる（日・月に1回）。文の生成と分類だけを数え、画像・音声・埋め込みなどは数えず止めない。`docs/ai.md`
- 記録と合計は、アプリのトランザクションが巻き戻っても残るよう、別の接続 `foundation_ai`（アプリが定めていればそれ、なければ既定の接続の写し）の書き込みの側で読み書きする。
- マイグレーションは `foundation.ai.enabled` のときだけ読まれる。無効でも `vendor:publish --tag=foundation-ai-migrations` で写せる。使用量の行は `model:prune --model=Miraqceed\Foundation\Ai\AiUsage` で消す（`foundation.ai.prune_days`、既定 90）。
- AI 向けの指針に節を足した（`foundation:install` にも写る）。

## 0.1.2

- コードのコメント・説明書・AI 向けの指針から、特定のサービスの名前と事故の番号を外し、一般的な書き方にした。振る舞いは 0.1.1 と同じ。

## 0.1.1

- composer.json のキーワードから `testing` を外した。Packagist が試し用のパッケージとみなし、入れ方を `composer require --dev` と案内していた。`--dev` で入れると本番に入らず、CSV・ログインのロック・CSP・見張りが動かない。入れ方は `composer require miraqceed/laravel-foundation`（`--dev` を付けない）。コードは 0.1.0 と同じ。

## 0.1.0

最初の版。Laravel 12〜13・PHP 8.3 以上。PostgreSQL 16 以上・MySQL 8.4・SQLite（試しのため）。Redis と常駐のプロセスに頼らない。

**入れただけではアプリの挙動は変わらない。ただしテストの DB の守りだけは、入れた時点で効く。** テストがつなぐ DB の名前に `test` か `testing` が語として要る（`app_test`）。止まったら `docs/test-safety.md` のとおりに直す。

- テストの DB の守り：印のない DB へつなごうとしたテストを、DB に触れる前に止める（`db.factory` を包む。並列の試しにも効く）。`docs/test-safety.md`
- CSV：`CsvResponse::download()`（式の守り・BOM・Shift_JIS・途中で失敗した印）、`CsvReader::parse()`（UTF-8・BOM 付き・Shift_JIS）、ずれない読み方 `Rows::cursor()`・`Rows::keyset()`。`docs/csv.md`
- ログインのロック：`LoginLock`（メールアドレスと接続元の組・接続元の2つの枠、IPv6 は /64）と Fortify への差し込み `AuthenticateWithLoginLock`。`docs/login.md`
- CSP・セキュリティヘッダー：ミドルウェア `foundation.security-headers`（既定は無効）、nonce、区分ごとの方針、違反の報告の口。`docs/security.md`
- 定期の処理の見張りと知らせ：`->watched()`・`foundation:record`・`foundation:watch`・`foundation:alerts:retry`、送り先は log・mail・webhook（HMAC の署名）・chatwork・slack の5つ（既定は無効）。ログの1行と Webhook の本文（v1）は知らせの `id` を持つ（受け取る側が送り直しの重複を除く）。知らせの表は `model:prune` で消す（設定 `monitoring.alerts.prune_days`、既定 90 日。**アプリが `model:prune --model=…Alert` を schedule に入れる**）。`docs/monitoring.md`
- AI 向けの指針（Laravel Boost の `resources/boost/guidelines/core.blade.php`）と、同じ原本から `AGENTS.md`・`CLAUDE.md` に節を書く `foundation:install`
