<?php

// Router for `php -S` in the `maintenance` service (docker-compose.yml). Traefik's failover
// sends traffic here only while no web container passes /up. Static on purpose: the app may be down.
http_response_code(503);
header('Retry-After: 30');
header('Cache-Control: no-store');

$message = '__APP_NAME__ is being updated. Please try again in a minute.';
$path = $_SERVER['REQUEST_URI'] ?? '/';

// API callers (JSON clients, webhooks) get the app's envelope and retry on 503.
if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || str_starts_with($path, '/api')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message, 'data' => null]);

    return;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>Under maintenance</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #0f172a;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 28rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { margin: 0; color: #475569; line-height: 1.5; }
    </style>
</head>
<body>
<main>
    <h1>We'll be right back</h1>
    <p><?= htmlspecialchars($message) ?> This page reloads by itself.</p>
</main>
</body>
</html>
