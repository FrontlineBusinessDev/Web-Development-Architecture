<?php

// Template: copy to app/Jobs/SendSuiteHubEvent.php unchanged. Contract: integrations/fbs-suites-hub.md in
// Web-Development-Architecture. The queue's retry_after must be longer than $timeout, and Redis must run with appendonly,
// because the last retry waits 12 hours.

namespace App\Jobs;

use App\Support\SuiteHub;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POSTs one event to the FBS Suites hub. The body is encoded once and that exact string is signed. Only the hub's own JSON
 * 2xx answer with success: true (a duplicate also answers 202) is done. A redirect (an http:// FBS_HUB_URL, a wrong address)
 * is never followed, because it would turn the POST into a GET and look like a success. Anything else throws, so the queue
 * retries on the backoff (6 tries in all).
 */
class SendSuiteHubEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 20;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $contactRef
     */
    public function __construct(public array $payload, public ?array $contactRef = null) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 43200];
    }

    public function handle(): void
    {
        $url = rtrim((string) config('services.fbs_hub.url'), '/');
        $secret = (string) config('services.fbs_hub.secret');
        if ($url === '' || $secret === '') {
            return;
        }

        $payload = $this->payload;
        if ($this->contactRef !== null) {
            $contact = SuiteHub::contact($this->contactRef);
            if ($contact === null) {
                return; // the tenant is gone or is a demo: nothing to report
            }
            $payload['contact'] = $contact;
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        $response = Http::timeout(10)
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders([
                'X-FBS-Event-Id' => $payload['id'],
                'X-FBS-Timestamp' => (string) $timestamp,
                'X-FBS-Signature' => SuiteHub::sign($secret, $timestamp, $json),
            ])
            ->withBody($json, 'application/json')
            ->post($url.'/hooks/'.$payload['app']);

        if ($response->redirect()) {
            throw new RuntimeException("The hub address redirected (HTTP {$response->status()}). Set FBS_HUB_URL to the exact FBS Suites address, https and no path.");
        }
        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException("The FBS Suites hub answered HTTP {$response->status()}, not its own success JSON.");
        }
    }
}
