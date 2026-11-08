<?php

// The virus check before an upload is stored (App\Support\VirusScan, rule 10). Copy to config/scan.php.
// Contract: Web-Development-Architecture integrations/fbs-suites-scanner.md. FBS People's config/scan.php is the reference.
return [

    // This app's key in FBS Suites (the same as services.fbs_hub.app): people, pay, connect, work, stock, crm, learn.
    'app' => '<app key>',

    // FBS Suites' scanner. Both set = scanning on (driver fbs); either empty = the local daemon below, or off.
    // Written by FBS Suites › Platform › Coolify › Connect; the secret is this app's scan secret, not the hub secret.
    'fbs' => [
        'url' => env('FBS_SCAN_URL'),
        'secret' => env('FBS_SCAN_SECRET'),
        'timeout' => 30, // seconds for one inline scan, FBS Suites answers within 25
    ],

    // A ClamAV daemon of your own (local development): 'clamav' when CLAMAV_HOST is set.
    'driver' => env('SCAN_DRIVER', env('CLAMAV_HOST') ? 'clamav' : 'off'),
    'host' => env('CLAMAV_HOST', '127.0.0.1'),
    'port' => (int) env('CLAMAV_PORT', 3310),
    'timeout' => (int) env('CLAMAV_TIMEOUT', 20), // seconds to connect and to wait for the verdict

    // With no scanner at all, refuse uploads that need a check instead of letting them through. Set true in production.
    'required' => (bool) env('SCAN_REQUIRED', false),

    // Inline up to 25 MB; up to 100 MB only where the caller can keep a file pending (the files explorer, async).
    'inline_max_bytes' => 25 * 1024 * 1024,
    'async_max_bytes' => 100 * 1024 * 1024,

    // When the scanner can't answer, per purpose (the X-FBS-Purpose label you pass to clean()).
    // refuse (any purpose not listed) | hold (stored as pending, re-scanned every 5 minutes) | open (stored, logged).
    // Infected is always refused. Contract section 6: hold and open only where it allows them.
    'policies' => [
        // 'leave' => 'hold',    // People: leave attachments (needs a pending state, download blocking and a re-scan job)
        // 'selfies' => 'open',  // People: kiosk and clock-in selfies, seen only by HR
    ],
];
