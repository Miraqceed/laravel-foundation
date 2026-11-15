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

    // ログインのロック（Task 5 の既定値）
    'login' => [
    ],
];
