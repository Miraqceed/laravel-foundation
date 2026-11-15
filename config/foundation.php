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
        // true にすると CSP を Report-Only で出す（X-Frame-Options は付いたまま）
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

        // schedule の外の処理（バックアップなど）。foundation:record {name} で終わりを記録する
        'external' => [
            'backup' => ['max_age_minutes' => 1560, 'label' => '日次のバックアップ'],
        ],
        // 見張りがすべて健全だったときに GET する外の死活監視の URL（空なら送らない）
        'heartbeat_url' => null,
        // foundation:watch を回す cron の式（monitoring が有効なときだけ schedule に入る）
        'watch_cron' => '0 * * * *',

        // 運営への知らせ（AlertSender）
        'alerts' => [
            // 同じ鍵の知らせを作らない間（分）。作った時刻で見る（送れたかは問わない）
            'throttle_minutes' => 60,
            // 送り先ごとの試す回数の上限。達したら諦める
            'max_attempts' => 10,
            // log・mail・webhook・chatwork・slack
            'channels' => ['log'],
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
];
