<?php

namespace App\Http\Controllers;

use App\Support\SuiteHub;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * POST /api/fbs-hub/check: FBS Suites › Platform › Apps › Test connection. The hub signs a "hub.check" with this app's
 * webhook secret (the same X-FBS-* headers and signature as our hooks). With a matching FBS_HUB_SECRET the app sends a
 * signed "hub.ping" to FBS_HUB_URL exactly as SendSuiteHubEvent posts events, and answers with what the hub said, so one
 * button proves both the secret and the address. A refusal answers 401 with its reason and the fingerprint (first 8 hex of
 * sha256) of our secret, never the secret. No session, no database, nothing stored.
 */
class SuiteHubCheckController extends Controller
{
    private const MAX_SKEW_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.fbs_hub.secret');
        $hubUrl = rtrim((string) config('services.fbs_hub.url'), '/');
        $app = (string) config('services.fbs_hub.app');
        $timestamp = (string) $request->header('X-FBS-Timestamp');
        $body = $request->getContent();
        $fingerprint = $secret === '' ? null : substr(hash('sha256', $secret), 0, 8);

        $reason = match (true) {
            $secret === '' => 'no_secret',
            ! ctype_digit($timestamp) => 'bad_timestamp',
            abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_SKEW_SECONDS => 'stale_timestamp',
            ! hash_equals(SuiteHub::sign($secret, (int) $timestamp, $body), (string) $request->header('X-FBS-Signature')) => 'bad_signature',
            default => null,
        };

        if ($reason !== null) {
            return response()->json(['success' => false, 'message' => 'Unauthorized', 'data' => ['reason' => $reason, 'app' => $app, 'fingerprint' => $fingerprint]], 401);
        }
        if ((json_decode($body, true)['event'] ?? null) !== 'hub.check') {
            return response()->json(['success' => false, 'message' => 'Not a hub check', 'data' => null], 422);
        }

        return response()->json(['success' => true, 'message' => 'The secret matches.', 'data' => [
            'app' => $app,
            'fingerprint' => $fingerprint,
            'hub_url' => $hubUrl === '' ? null : $hubUrl,
            'hub_reply' => $hubUrl === '' ? null : self::ping($hubUrl, $secret, $app),
        ]]);
    }

    /**
     * A signed hub.ping, posted like SendSuiteHubEvent (no redirects followed). The hub answers it 200 and stores nothing.
     *
     * @return array{status: int|null, success: bool, message: string}
     */
    private static function ping(string $hubUrl, string $secret, string $app): array
    {
        $id = (string) Str::uuid();
        $json = (string) json_encode(['id' => $id, 'event' => 'hub.ping', 'version' => 1, 'occurred_at' => now()->toIso8601String(), 'app' => $app], JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(8)
                ->withoutRedirecting()
                ->acceptJson()
                ->withHeaders([
                    'X-FBS-Event-Id' => $id,
                    'X-FBS-Timestamp' => (string) $timestamp,
                    'X-FBS-Signature' => SuiteHub::sign($secret, $timestamp, $json),
                ])
                ->withBody($json, 'application/json')
                ->post($hubUrl.'/hooks/'.$app);
        } catch (ConnectionException $e) {
            return ['status' => null, 'success' => false, 'message' => "Could not reach {$hubUrl}: ".mb_substr($e->getMessage(), 0, 200)];
        }

        $message = match (true) {
            $response->redirect() => 'The address redirected to '.($response->header('Location') ?: 'another address').': set FBS_HUB_URL to the exact FBS Suites address, https and no path.',
            is_string($response->json('message')) => mb_substr((string) $response->json('message'), 0, 300),
            default => "HTTP {$response->status()}, not the hub's JSON: check FBS_HUB_URL.",
        };

        return ['status' => $response->status(), 'success' => $response->successful() && $response->json('success') === true, 'message' => $message];
    }
}
