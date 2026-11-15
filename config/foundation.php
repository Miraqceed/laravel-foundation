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
        // 接続元が当てにならない構成（IP が全員同じになるなど）では true にして、メールアドレスだけで数える。
        // このとき接続元ごとの枠は使わない
        'email_only' => false,
        'email_only_max' => 10,
        'email_only_decay_seconds' => 1800,
    ],
];
