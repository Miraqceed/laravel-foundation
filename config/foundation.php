<?php

return [
    // テストの守り（Task 2・3）
    'test_safety' => [
        'markers' => ['test', 'testing'],
        'ignore_connections' => [],
        // true にすると、テストの中だけ Http::preventStrayRequests() を掛ける（Http::fake() に当たらない要求は送る前に止まる）。
        // 判定は DB の守りと同じ（テストの中か）。テストの外（本番・artisan）では掛けない
        'prevent_stray_requests' => false,
    ],

    // CSP・セキュリティヘッダー（Task 6）
    'security' => [
        // 偽のあいだ、別名 foundation.security-headers は何もしない
        'enabled' => false,
        // true にすると CSP を Report-Only で出す（X-Frame-Options は区分の frame-ancestors から付いたまま：
        // 'none' か未指定なら DENY、'self' だけなら SAMEORIGIN、ほかの元を含めば付けない）
        'report_only' => false,
        'hsts' => false,
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',

        // 区分ごとの方針。「'nonce'」と書いた元だけが 'nonce-…' に置き換わる。
        // 外の元（決済・Turnstile・書体など）はアプリが足す。リストはアプリの値で置き換わる
        'areas' => [
            'web' => [
                'default-src' => ["'self'"],
                'script-src' => ["'self'", "'nonce'"],
                'style-src' => ["'self'", "'nonce'"],
                'style-src-attr' => ["'unsafe-inline'"],
                'img-src' => ["'self'", 'data:'],
                'font-src' => ["'self'"],
                'connect-src' => ["'self'"],
                'frame-src' => ["'none'"],
                'object-src' => ["'none'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'none'"],
            ],
            // Filament を想定。Livewire・Alpine が要るので、nonce は使わない
            'admin' => [
                'default-src' => ["'self'"],
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
                'style-src' => ["'self'", "'unsafe-inline'"],
                'style-src-attr' => ["'unsafe-inline'"],
                'img-src' => ["'self'", 'data:'],
                'font-src' => ["'self'"],
                'connect-src' => ["'self'"],
                'frame-src' => ["'none'"],
                'object-src' => ["'none'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'none'"],
            ],
            'api' => [
                'default-src' => ["'none'"],
                'frame-ancestors' => ["'none'"],
            ],
        ],
        // 上から順に当て、どれにも当たらなければ web
        'area_paths' => [
            'admin' => ['admin', 'admin/*'],
            'api' => ['api/*'],
        ],
        // CSP の違反の報告を受けるパス（例 'csp-report'）。決めたときだけ経路を登録し、方針に report-uri を足す
        'report_route' => null,
    ],

    // 見張りと知らせ（Task 7・8）
    'monitoring' => [
        'enabled' => false,

        // schedule の外の処理。既定は空（アプリが書いた分だけ見張る）。foundation:record {name} で終わりを記録する。
        // 例：'external' => ['backup' => ['max_age_minutes' => 1560, 'label' => '日次のバックアップ']]
        'external' => [],
        // 見張りがすべて健全だったときに GET する外の死活監視の URL（空なら送らない）
        'heartbeat_url' => null,
        // foundation:watch を回す cron の式（monitoring が有効なときだけ schedule に入る）
        'watch_cron' => '0 * * * *',
        // 見張る database のキュー：接続 => [キュー => 許す待ち（分）]。CronWorker::schedule() で登録したキューは、書かなくても10分で見る
        // 例：'database' => ['mail' => 5, 'heavy' => 60]
        'queues' => [],
        // DB の大きさ（MySQL・MariaDB・PostgreSQL）。capacity_bytes が null なら見ない。容量の alert_ratio 以上で知らせる
        'database' => ['capacity_bytes' => null, 'alert_ratio' => 0.7],

        // 運営への知らせ（AlertSender）
        'alerts' => [
            // 同じ鍵の知らせを作らない間（分）。作った時刻で見る（送れたかは問わない）。
            // 10より大きいときは5分短く見る（毎時の見張りと60分の間引きで、送り直しの秒のずれで1時間おきに抜けないように）
            'throttle_minutes' => 60,
            // 送り先ごとの試す回数の上限。達したら諦める
            'max_attempts' => 10,
            // 送れたか諦めてこの日数がたった知らせを model:prune で消す（アプリが schedule に入れる：
            // Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Monitoring\Alerts\Alert::class]])->daily();）
            'prune_days' => 90,
            // log・mail・webhook・chatwork・slack
            'channels' => ['log'],
            // log の送り先：チャネル（null ならアプリの既定のチャネル）と重さ。
            // コンテナの標準エラーを集めて運ぶ環境では channel を 'stderr' にする。チャネルの level（LOG_LEVEL）より低い重さは捨てられ、送れた扱いになる
            'log' => ['channel' => null, 'level' => 'error'],
            'mail' => ['to' => null],
            'webhook' => ['url' => null, 'secret' => null],
            'chatwork' => ['token' => null, 'room_id' => null],
            'slack' => ['webhook_url' => null],
        ],
    ],

    // ログインのロック（LoginLock）
    'login' => [
        // メールアドレスと接続元の組ごとの失敗の上限と窓（秒）
        'account_max' => 5,
        'decay_seconds' => 60,
        // 接続元ごとの失敗の上限（メールを変えながら試すスプレーを止める）。窓は decay_seconds
        'client_max' => 20,
        // true にすると、メールアドレスと接続元の組の枠（account_max・decay_seconds）を、
        // メールアドレスだけの枠（email_only_max・email_only_decay_seconds）に置き換える。
        // 接続元ごとの枠（client_max）は残る
        'email_only' => false,
        'email_only_max' => 10,
        'email_only_decay_seconds' => 1800,
        // 現在のパスワードの確かめ（attemptPasswordConfirmation）の、利用者ごとの失敗の上限と窓（秒）。
        // 接続元を替えても数えは同じ。利用者と接続元の組の枠（account_max・decay_seconds）も残る
        'password_account_max' => 10,
        'password_account_decay_seconds' => 1800,
    ],

    // 実在しないメールアドレスへ送らない（Mail\DropReservedRecipients）・メールのリンクを APP_URL から作る（Mail\AppUrlLinks）
    'mail' => [
        // 真にすると、実在しないことが決まっている宛先（example.com・.invalid など）を、送る直前に To・Cc・Bcc から外す。
        // 宛先が残らなければ送信を取りやめる（Mail::send() は null を返す）
        'drop_reserved_recipients' => false,
        // 真にすると、再設定・確認のメールのリンクを、要求の Host でなく APP_URL から作る。
        // アプリが自分で ResetPassword・VerifyEmail の createUrlUsing を書いていれば、アプリのものが使われる
        'links_from_app_url' => false,
        // Mail\NormalizeEmailInput（アプリが入れるミドルウェア）が、検証の前に半角にそろえる欄の名前。
        // 入れ子（rows.0.email）と、それぞれの <名前>_confirmation も整える。new_email などは足す
        'input_fields' => ['email'],
        // 真にすると、送るすべてのメールに Auto-Submitted: auto-generated を足す（自動の返信を止める。既にあれば変えない）。
        // 人が書いて人に送るメールも送るアプリは偽のまま、自動のメールにだけ Mail\AutomatedMessage::mark() を使う
        'auto_submitted' => false,
        // Mail\NotDisposableEmail が読む、使い捨てのメールのドメインの一覧のファイル（1行に1つ。# は注釈）。パッケージは一覧を持たない。
        // アプリが置き、取り直す（例：base_path('resources/data/disposable-email-domains.txt')）。null のまま規則を使うと LogicException
        'disposable_domains' => null,
    ],

    // 稼働の確かめ（Health\HealthCheck・Health::route()）。経路はアプリが bootstrap/app.php で登録する
    'health' => [
        // 確かめの中で見るもの。'database' は既定の接続で select 1 を流す（DB の一時の不調でも NG になる）。
        // 空なら何も見ない（Laravel の既定の /up と同じ）。知らない名前は起動を止めず、確かめが NG になる
        'checks' => [],
    ],

    // AI の費用の上限と記録（laravel/ai を使うアプリだけ）
    'ai' => [
        // 既定で無効。入れただけでは挙動も表も変わらない
        'enabled' => false,
        // アプリ全体の1日・1か月の金額の上限（円。小数も可）。null か空は上限なし、0 はすべて止める
        'daily_limit_yen' => null,
        'monthly_limit_yen' => null,
        // 1日の呼び出し回数の上限。null か空は上限なし
        'daily_call_limit' => null,
        // 金額を数えられないとき（単価のないモデル・数でない上限など）の扱い：block（止める）か allow（数えられない上限だけを外す）
        'when_uncountable' => 'block',
        // 1ドルの円。単価（ドル）を円にするときの換算
        'yen_per_usd' => 150,
        // 単価（ドル／100万トークン）。既定値は入れない（各社の値が変わると古くなる）。形：
        // ['<driver>' => ['<model>' => ['input' => 0.0, 'output' => 0.0, 'cache_write' => 0.0, 'cache_read' => 0.0]]]
        'prices' => [],
        // 使用量の行を model:prune で消す日数（アプリが schedule に入れる：
        // Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Ai\AiUsage::class]])->daily();）。日ごとの合計は消さない
        'prune_days' => 90,
    ],

    // 文の中の秘密を伏せる（Redactor）。呼んだときだけ働く。漏れを無くすとは約束しない
    'redaction' => [
        // 伏せた所に入れる印。どの形にも当たらない字にする（当たると作るときに例外）
        'marker' => '[REDACTED]',
        // 使う組み込みの種類。null は全部。例：メールアドレスを残すなら email を外した並び
        // private_key・aws_key・github_token・api_key・slack_token・jwt・assignment・url_password・email・phone
        'kinds' => null,
        // アプリが足す形（種類の名前 => 正規表現）。例：'myapp_token' => '/(?<![A-Za-z0-9])myapp_[A-Za-z0-9]{32}(?![A-Za-z0-9])/'
        'patterns' => [],
    ],

    // ログイン以外の照合の数え（Attempts）。呼んだときだけ働く
    'attempts' => [
        // 診断の道具（ZAP など）で認証の経路を調べる間だけ、数えずに照合する。production では効かない（数えて report() する）
        'bypass' => false,
        // 続けた失敗（確認コードなど）：時間で消えない数えの上限と、上限に達したときのロックの秒数
        'consecutive' => [
            'max' => 10,
            'lock_seconds' => 1800,
        ],
    ],

    // 1回だけ使える合言葉（OneTimeCodes）。呼んだときだけ働く
    'one_time_codes' => [
        // 既定で無効。真のときだけ表のマイグレーションを読み込む（vendor:publish --tag=foundation-one-time-code-migrations でも写せる）
        'enabled' => false,
        // 期限・使った時刻・失効の時刻のどれかがこの日数より前の行を、model:prune で消す（アプリが schedule に入れる：
        // Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\OneTimeCodes\OneTimeCode::class]])->daily();）
        'prune_days' => 7,
    ],

    // 二段階の認証（認証アプリの確認コード。TwoFactor・FortifyTwoFactorProvider）。呼んだときだけ働く
    'two_factor' => [
        // 登録の URI の issuer。null なら app.name
        'issuer' => null,
        // 前後に受ける刻みの数（30秒の刻み。時計のずれ）
        'window' => 1,
        // 手書きのアプリ向けの TwoFactor が使う表（null ならモデルの表）と列（vendor:publish --tag=foundation-two-factor-migrations で列を足す。
        // マイグレーションは table が null なら users に足す）
        'table' => null,
        'columns' => [
            'secret' => 'two_factor_secret',
            'confirmed_at' => 'two_factor_confirmed_at',
            'last_step' => 'two_factor_last_step',
        ],
    ],

    // 投げない記録（Failures\FailureReporter）。呼んだときだけ働く
    'failures' => [
        // 記録の間引きの目印を置くディレクトリ。null は storage/framework/foundation/report-throttle
        'throttle_path' => null,
    ],

    // Cloudflare Turnstile（Turnstile\Turnstile・TurnstilePassed・VerifyTurnstile）。使ったときだけ働く。
    // 鍵はここに置かない：アプリが config/services.php の turnstile.site_key・secret_key に書く
    'turnstile' => [
        // 真にすると、訪問者の IP を Cloudflare へ送る（remoteip。任意の値）。送るなら trustProxies が要る（前段の IP を送らないため）
        'send_ip' => false,
        // 答えの hostname を比べるときの、受ける名前の配列（例：['app.example.com']）。null なら比べない
        'hostnames' => null,
        // ウィジェットのトークンの欄の名前（ミドルウェアが読む）
        'field' => 'cf-turnstile-response',
        // VerifyTurnstileOnNamedRoutes（web のグループに入れる）が確かめる経路の名前（例：['login.store', 'register.store', 'password.email']）
        'routes' => [],
    ],

    // アカウントの守り（Accounts\PasswordSessions など）。呼んだときだけ働く
    'accounts' => [
        // パスワードの後のセッションの後始末が使うガード（セッションのガード）
        'guard' => 'web',
    ],

    // メールアドレスの変更と戻すリンク（Accounts\EmailChanges・Rules\NotHeldEmail）。呼んだときだけ働く
    'email_changes' => [
        // 既定で無効。真のときだけ表のマイグレーションを読み込む（vendor:publish --tag=foundation-email-change-migrations でも写せる）
        'enabled' => false,
        // 新しいアドレスへの確認のリンクの期限（分）
        'confirm_minutes' => 60,
        // 古いアドレスへの戻すリンクの期限（日）。古いアドレスをほかの人に使わせない押さえも同じ間
        'undo_days' => 7,
        // 利用者のモデルのメールアドレスの列
        'email_column' => 'email',
        // 押さえで「同じアドレス」とみなす範囲（Login\EmailKey のクラス）。null なら NormalizedEmailKey
        'email_key' => null,
        // アドレスがほかのアカウントに使われているか（Accounts\EmailAvailability のクラス）。null なら ModelEmailAvailability
        'availability' => null,
        // 戻すときに API キー・連携などを消す差し込み（Accounts\AccountLockdown のクラス）。null なら何もしない
        'lockdown' => null,
        // 再設定のトークンを消すブローカー。null なら auth.defaults.passwords。false なら消さない（再設定を持たないアプリ）
        'password_broker' => null,
        // もう使えない行を、この日数の後に model:prune で消す（アプリが schedule に入れる：
        // Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Accounts\EmailChange::class]])->daily();）
        'prune_days' => 7,
    ],

    // 知らせを1回だけ送る（Miraqceed\Foundation\Notices\OnceNotices）。呼んだときだけ働く
    'notices' => [
        // 既定で無効。真のときだけ表のマイグレーションを読み込む（vendor:publish --tag=foundation-notice-migrations でも写せる）
        'enabled' => false,
        // 取ったまま送っていない印を、取り直すまでの分。キューで送るときは、キューの待ち＋retry_after より長くする
        'claim_minutes' => 15,
        // 印を残す日数（send()・claim() に keepUntil を渡さないとき）。過ぎた行は model:prune で消す（アプリが schedule に入れる：
        // Schedule::command('model:prune', ['--model' => [\Miraqceed\Foundation\Notices\Notice::class]])->daily();）
        'keep_days' => 400,
    ],

    // 消す決まりの台帳（Miraqceed\Foundation\Retention）。書かなければ、試しの助け・deploy-check（skip）・Erasure は働かない
    'retention' => [
        // アプリの台帳のクラス（Miraqceed\Foundation\Retention\DataMap を実装する）。null なら台帳なし
        'map' => null,
    ],

    // 本番の設定の点検（php artisan foundation:deploy-check）。流したときだけ働く
    'deploy_check' => [
        // アプリの調べのクラス（Miraqceed\Foundation\DeployCheck\Check。容器から作る）。並びのとおりに、パッケージの既定の調べの後に走る
        'checks' => [],
        // このアプリに当たらない既定の調べの id（例：API だけのアプリの session_secure_cookie）
        'skip' => [],
        // 'required' にすると、信頼するプロキシ（trusted_proxies）に前段の IP・範囲がなければ失敗にする
        // （訪問者の本当の IP が要る、逆プロキシの後ろのアプリ）。null なら状態を見せるだけ
        'proxies' => null,
        // mail_domain で DKIM を見るときの、メール事業者のセレクター（<セレクター>._domainkey.<ドメイン>）。null なら DKIM は見ない
        'dkim_selector' => null,
        // db_masked_bindings で、既定の接続のほかに見る接続の名前（例：['mysql_logs']）
        'connections' => [],
    ],
];
