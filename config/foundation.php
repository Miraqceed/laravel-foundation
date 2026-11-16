<?php

return [
    // テストの守り（Task 2・3）
    'test_safety' => [
        'markers' => ['test', 'testing'],
        'ignore_connections' => [],
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
    ],

    // AI の費用の上限と記録（laravel/ai を使うアプリだけ。部品6）
    'ai' => [
        // 既定で無効。入れただけでは挙動も表も変わらない
        'enabled' => false,
        // アプリ全体の1日・1か月の金額の上限（円。小数も可）。null か空は上限なし、0 はすべて止める
        'daily_limit_yen' => null,
        'monthly_limit_yen' => null,
        // 1日の呼び出し回数の上限。null か空は上限なし
        'daily_call_limit' => null,
        // 金額を数えられないとき（単価のないモデル・数でない上限など）の扱い：block（止める）か allow
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
];
