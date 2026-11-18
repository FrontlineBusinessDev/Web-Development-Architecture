<?php

namespace App\Jobs;

use App\FbsId\FbsId;
use App\Models\Company;
use App\Models\FbsChange;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one company's changes of shared records to the linked FBS apps (contract fbs-record.v1, plan 2026-11-18-every-app-two-way-sync):
 * People's own changes, and the changes it took as the `person` center, which go to every linked app except the one that made them.
 * The linked apps come from FBS Suites (connector-peers), each call carries a 5-minute hub token. A change waits until every app it goes
 * to has answered; the next run sends it again.
 */
class PushFbsChanges implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 50;

    public int $uniqueFor = 120;

    public function __construct(public int $companyId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(): void
    {
        if (! FbsId::enabled()) {
            return;
        }
        Company::run($this->companyId, function () {
            $own = FbsChange::whereNull('received_from')->where('status', 'pending')->orderBy('id')->limit(500)->get();
            $taken = FbsChange::where('forward_status', 'pending')->orderBy('id')->limit(500)->get();
            if ($own->isEmpty() && $taken->isEmpty()) {
                return;
            }
            $peers = $this->peers();
            if ($peers === null) {
                return; // FBS Suites did not answer: everything waits for the next run
            }
            $this->deliver($own, $peers, 'status');
            $this->deliver($taken, $peers, 'forward_status');
        });
    }

    /**
     * @param  Collection<int, FbsChange>  $changes
     * @param  list<array{app: string, tenant: string, base_url: string, types: list<string>}>  $peers
     */
    private function deliver(Collection $changes, array $peers, string $column): void
    {
        foreach ($changes->groupBy('type') as $type => $group) {
            $takers = array_values(array_filter($peers, fn (array $p) => in_array($type, $p['types'], true)));
            foreach ($takers as $peer) {
                // never back to the app that sent or made it (the loop guard)
                $this->send($peer, (string) $type, $group->filter(fn (FbsChange $c) => ! in_array($peer['app'], [$c->received_from, $c->changed_by['app'] ?? null], true)
                    && ! isset(($c->targets ?? [])[$this->key($peer)])));
            }
            foreach ($group as $change) {
                $due = array_map(fn (array $p) => $this->key($p), array_filter($takers, fn (array $p) => ! in_array($p['app'], [$change->received_from, $change->changed_by['app'] ?? null], true)));
                if ($due === []) {
                    $change->forceFill([$column => 'none'])->save();
                } elseif (array_diff($due, array_keys($change->targets ?? [])) === []) {
                    $change->forceFill([$column => 'accepted'])->save();
                }
            }
        }
    }

    /** @return list<array{app: string, tenant: string, base_url: string, types: list<string>}>|null null when FBS Suites did not answer */
    private function peers(): ?array
    {
        try {
            $res = $this->hub()->get(FbsId::issuer().'/api/fbs-id/connector-peers', ['tenant' => (string) $this->companyId]);
        } catch (Throwable) {
            return null;
        }
        if (! $res->successful() || $res->json('success') !== true) {
            return null;
        }
        $peers = [];
        foreach ((array) $res->json('data.peers', []) as $p) { // FBS Suites' answer, checked like any outside input
            if (is_array($p) && is_string($p['app'] ?? null) && is_string($p['tenant'] ?? null) && is_string($p['base_url'] ?? null) && is_array($p['types'] ?? null)) {
                $peers[] = ['app' => $p['app'], 'tenant' => $p['tenant'], 'base_url' => $p['base_url'], 'types' => array_values(array_filter($p['types'], 'is_string'))];
            }
        }

        return $peers;
    }

    /**
     * @param  array{app: string, tenant: string, base_url: string}  $peer
     * @param  iterable<FbsChange>  $changes
     */
    private function send(array $peer, string $type, iterable $changes): void
    {
        $body = collect($changes)->map(fn (FbsChange $c) => ['change_id' => $c->change_id, 'type' => $c->type, 'external_id' => $c->external_id,
            'origin' => ['app' => $c->origin_app, 'external_id' => $c->external_id], 'changed_by' => $c->changed_by,
            'changed_at' => $c->changed_at->toIso8601ZuluString('microsecond'), 'fields' => $c->fields])->values();
        if ($body->isEmpty()) {
            return;
        }
        try {
            $token = $this->hub()->post(FbsId::issuer().'/api/fbs-id/connector-token', ['from_tenant' => (string) $this->companyId,
                'to_app' => $peer['app'], 'to_tenant' => $peer['tenant'], 'scope' => "connector.{$type}.write"])->json('data.token');
            $res = is_string($token) ? Http::timeout(15)->acceptJson()->withToken($token)->withoutRedirecting()
                ->put(rtrim($peer['base_url'], '/').'/api/fbs/connector/changes', ['changes' => $body->all()]) : null;
        } catch (Throwable) {
            return; // waits for the next run
        }
        $answers = $res?->successful() && $res->json('success') === true ? (array) $res->json('data.answers', []) : [];
        foreach ($changes as $change) {
            if (isset($answers[$change->change_id])) {
                $change->forceFill(['targets' => [...($change->targets ?? []), $this->key($peer) => (string) $answers[$change->change_id]]])->save();
            }
        }
    }

    /** @param  array{app: string, tenant: string}  $peer */
    private function key(array $peer): string
    {
        return "{$peer['app']}:{$peer['tenant']}";
    }

    private function hub(): PendingRequest
    {
        return Http::timeout(8)->acceptJson()->withBasicAuth(FbsId::clientId(), FbsId::clientSecret());
    }
}
