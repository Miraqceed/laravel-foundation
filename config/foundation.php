<?php

return [
    // テストの守り（Task 2・3）
    'test_safety' => [
        'markers' => ['test', 'testing'],
        'ignore_connections' => [],
    ],

    // CSP・セキュリティヘッダー（Task 6）
    'security' => [
        'enabled' => false,
    ],

    // 見張りと知らせ（Task 7・8）
    'monitoring' => [
        'enabled' => false,
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
