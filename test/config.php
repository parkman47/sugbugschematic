<?php
return [
    'data_dir' => __DIR__ . '/runtime-data',
    'admin_token' => 'browser-test-admin-token-more-than-32-characters',
    'max_report_bytes' => 15 * 1024 * 1024,
    'projects' => [
        'browser-test' => [
            'submit_keys' => ['browser-test-key'],
            'allowed_origins' => ['http://127.0.0.1:8791'],
            'retention_on_resolve' => 'metadata',
            'max_reports_per_hour_per_ip' => 20,
        ],
    ],
];
