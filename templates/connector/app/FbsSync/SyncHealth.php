<?php

namespace App\FbsSync;

use App\FbsId\FbsId;
use App\Models\FbsChange;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How this tenant's sync with each linked app stands (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync Task 6, spec §11 C1),
 * worked out from `fbs_changes`: the app's own changes (and, at a type's center, the ones it forwards) that still wait for that app
 * and since when, the last one it answered, what it sent that waits for an admin here, and 24-hour counts. Counts and times only, never
 * a record's values (rule 18). Reported to FBS Suites after each push (Platform › Connectors).
 */
final class SyncHealth
{
    /**
     * @param  array{app: string, tenant: string}  $peer
     * @param  array{at: CarbonInterface, error: string}|null  $failure  this run's failure towards the peer, if any
     * @return array<string, mixed>
     */
    public static function of(array $peer, ?array $failure = null): array
    {
        $key = "{$peer['app']}:{$peer['tenant']}";
        // what goes to this app: never back to the app that sent or made it (a non-center app: only its own changes, to the peers it sends to)
        $due = fn (FbsChange $c) => ! isset(($c->targets ?? [])[$key]) && ! in_array($peer['app'], [$c->received_from, $c->changed_by['app'] ?? null], true);
        // the center also counts what it forwards (`forward_status`); a non-center app drops that orWhere
        $waiting = FbsChange::where(fn ($q) => $q->where(fn ($w) => $w->whereNull('received_from')->where('status', 'pending'))->orWhere('forward_status', 'pending'))
            ->get(['changed_at', 'targets', 'received_from', 'changed_by'])->filter($due);
        $answered = FbsChange::whereNotNull('targets')->where('updated_at', '>=', now()->subDays(30))->orderByDesc('updated_at')->limit(500)
            ->get(['updated_at', 'targets'])->filter(fn (FbsChange $c) => isset(($c->targets ?? [])[$key]));
        $from = FbsChange::where('received_from', $peer['app']);

        return [
            'app' => $peer['app'], 'tenant' => $peer['tenant'],
            'sent_ok_at' => $answered->first()?->updated_at?->toIso8601String(),
            'failed_at' => $failure ? $failure['at']->toIso8601String() : null,
            'error' => $failure ? mb_substr($failure['error'], 0, 200) : null,
            'pending' => $waiting->count(),
            'oldest_pending_at' => $waiting->min('changed_at')?->toIso8601String(),
            'held' => (clone $from)->where('status', 'held')->count(),
            'sent_24h' => $answered->where('updated_at', '>=', now()->subDay())->count(),
            'received_24h' => (clone $from)->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Tells FBS Suites, best effort: a report that fails is simply sent with the next push.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function report(string $tenant, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            Http::timeout(8)->acceptJson()->withBasicAuth(FbsId::clientId(), FbsId::clientSecret())
                ->post(FbsId::issuer().'/api/fbs-id/connector-report', ['tenant' => $tenant, 'peers' => $rows]);
        } catch (Throwable) {
            // the next push reports again
        }
    }
}
