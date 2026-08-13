<?php
declare(strict_types=1);

// Copy to config.php, keep it outside source control, and replace every secret.
return [
    // This directory must be writable by PHP and must not be inside the web root.
    'data_dir' => dirname(__DIR__) . '/data',
    'admin_token' => 'replace-with-at-least-32-random-characters',
    'max_report_bytes' => 15 * 1024 * 1024,
    'projects' => [
        'weblod' => [
            // Browser-visible project identifier, not an administrative secret.
            'submit_keys' => ['replace-this-browser-visible-project-key'],
            'allowed_origins' => ['https://example.com'],
            'allow_missing_origin' => false,
            'retention_on_resolve' => 'metadata', // keep, metadata, or delete
            'max_reports_per_hour_per_ip' => 20,
        ],
    ],
];
