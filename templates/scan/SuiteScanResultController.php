<?php

namespace App\Http\Controllers;

use App\Support\VirusScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/fbs-scan/result: FBS Suites' answer to an async scan (VirusScan::queue). Signed hub-style with this app's scan
 * secret (X-FBS-Event-Id = the scan id, X-FBS-Timestamp, X-FBS-Signature over timestamp + "." + raw body). Route it
 * outside the session, CSRF and auth, like /api/fbs-hub/check. Only apps with async uploads need it.
 *
 * The verdict is handed to the app as the string event "fbs-scan.result" with the decoded body
 * ({ scan_id, verdict, signature, reason, ... }): a listener releases the pending file on `clean`, deletes it and tells
 * the uploader on `infected`, and applies the purpose's policy on `error`. Answer 2xx only once it is handled, so
 * FBS Suites retries otherwise. Copied from Web-Development-Architecture templates/scan/.
 */
class SuiteScanResultController extends Controller
{
    private const MAX_SKEW_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('scan.fbs.secret');
        $timestamp = (string) $request->header('X-FBS-Timestamp');
        $body = $request->getContent();

        $reason = match (true) {
            $secret === '' => 'no_secret',
            ! ctype_digit($timestamp) => 'bad_timestamp',
            abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_SKEW_SECONDS => 'stale_timestamp',
            ! hash_equals(VirusScan::signBody($secret, (int) $timestamp, $body), (string) $request->header('X-FBS-Signature')) => 'bad_signature',
            default => null,
        };
        if ($reason !== null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized', 'data' => ['reason' => $reason]], 401);
        }

        $result = json_decode($body, true);
        if (! is_array($result) || ! is_string($result['scan_id'] ?? null) || $result['scan_id'] !== $request->header('X-FBS-Event-Id')
            || ! in_array($result['verdict'] ?? null, ['clean', 'infected', 'error'], true)) {
            return response()->json(['success' => false, 'message' => 'Not a scan result', 'data' => null], 422);
        }

        event('fbs-scan.result', [$result]);

        return response()->json(['success' => true, 'message' => 'Recorded', 'data' => ['scan_id' => $result['scan_id']]]);
    }
}
