<?php

// The virus check before an upload is stored (App\Support\VirusScan, rule 10). Copy to config/scan.php.
// Contract: Web-Development-Architecture integrations/fbs-suites-scanner.md.
return [

    // This app's key in FBS Suites (the same as services.fbs_hub.app): people, pay, connect, work, stock, crm, learn.
    'app' => '<app key>',

    // FBS Suites' scanner. Both set = scanning on (driver fbs); either empty = scanning off, uploads work as before.
    // Written by FBS Suites › Platform › Coolify › Connect; the secret is this app's scan secret, not the hub secret.
    'fbs' => [
        'url' => env('FBS_SCAN_URL'),
        'secret' => env('FBS_SCAN_SECRET'),
    ],

    // Local development only: a clamd you run yourself (SCAN_DRIVER=clamav, CLAMAV_HOST=127.0.0.1).
    'driver' => env('SCAN_DRIVER', 'none'),
    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 20),
    ],

    // Over this the app refuses before upload; between 25 MB and this, VirusScan::queue() (async).
    'async_max_bytes' => 100 * 1024 * 1024,

    // What happens when the scanner can't answer, per purpose (the X-FBS-Purpose label, ^[a-z0-9-]{1,40}$).
    // refuse (default for any purpose not listed) | hold (store as pending, re-scan) | open (store, logged unchecked).
    // Infected is always refused. Contract section 6 lists where hold and open are allowed.
    'policies' => [
        // 'leave-attachment' => 'hold',
        // 'clock-in-selfie' => 'open',
    ],
];
