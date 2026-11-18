<?php

namespace App\FbsSync;

use App\FbsId\FbsId;
use App\Models\Company;
use App\Models\FbsTally;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The nightly tally (contract fbs-record.v1 "Tally"; fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync Task 6): People, the
 * `person` center, asks each linked app for a salted hash of each shared field of the people it holds, and compares them with its
 * own. What differs is kept by external id and field name only (FbsTally), shown in `sync_status` and reported to FBS Suites with
 * the sync health (Platform › Connectors). Nothing is changed: a difference is for a person to look at, or the next edit to fix.
 */
final class Tally
{
    /** The shared fields compared (employment type is left out: each app words it its own way). */
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'mobile', 'employee_number', 'department', 'position', 'date_hired', 'status'];

    /** How many differing records are kept per app (the count is always whole). */
    public const KEEP = 200;

    /** Compares the company with every linked app that takes `person`, then reports to FBS Suites. */
    public static function run(int $companyId): void
    {
        if (! FbsId::enabled()) {
            return;
        }
        Company::run($companyId, function () use ($companyId) {
            $peers = self::peers($companyId);
            if ($peers === null) {
                return;
            }
            $rows = [];
            foreach ($peers as $peer) {
                $t = self::with($companyId, $peer);
                $rows[] = SyncHealth::of($peer) + ['tally' => ['checked' => $t->checked, 'differ' => $t->differ_count, 'missing' => $t->missing,
                    'error' => $t->error, 'ran_at' => $t->ran_at->toIso8601String()]];
            }
            SyncHealth::report((string) $companyId, $rows);
        });
    }

    /** @param  array{app: string, tenant: string, base_url: string}  $peer */
    public static function with(int $companyId, array $peer): FbsTally
    {
        $salt = bin2hex(random_bytes(16));
        $answer = null;
        try {
            $token = self::hub()->post(FbsId::issuer().'/api/fbs-id/connector-token', ['from_tenant' => (string) $companyId,
                'to_app' => $peer['app'], 'to_tenant' => $peer['tenant'], 'scope' => 'connector.person.read'])->json('data.token');
            $res = is_string($token) ? Http::timeout(30)->acceptJson()->withToken($token)->withoutRedirecting()
                ->post(rtrim($peer['base_url'], '/').'/api/fbs/connector/tally', ['type' => 'person', 'salt' => $salt]) : null;
            $answer = $res?->successful() && $res->json('success') === true ? $res->json('data.records') : null;
            $error = $answer === null ? ($res === null ? 'FBS Suites gave no connector token.' : 'FBS '.ucfirst($peer['app'])." answered {$res->status()}.") : null;
        } catch (Throwable) {
            $error = 'FBS '.ucfirst($peer['app']).' or FBS Suites did not answer.';
        }

        $checked = $missing = 0;
        $differ = [];
        foreach (is_array($answer) ? $answer : [] as $externalId => $theirs) {
            if (! is_string($externalId) || ! is_array($theirs)) {
                continue;
            }
            $e = PersonType::find($externalId);
            if ($e === null) {
                $missing++;

                continue;
            }
            $checked++;
            $mine = self::hashes(PersonType::fields($e), $salt);
            $fields = array_values(array_filter(array_keys(array_intersect_key($mine, $theirs)), fn (string $f) => $mine[$f] !== $theirs[$f]));
            if ($fields !== []) {
                $differ[] = ['external_id' => $externalId, 'fields' => $fields];
            }
        }

        $tally = FbsTally::firstOrNew(['peer_app' => $peer['app'], 'peer_tenant' => $peer['tenant']]);
        $tally->fill(['checked' => $checked, 'differ' => array_slice($differ, 0, self::KEEP), 'differ_count' => count($differ), 'missing' => $missing,
            'error' => $error, 'ran_at' => now()])->save();

        return $tally;
    }

    /**
     * The same hashing every app's tally answer uses: lower case, single spaces, status as active / inactive.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    public static function hashes(array $fields, string $salt): array
    {
        $out = [];
        foreach (array_intersect_key($fields, array_flip(self::FIELDS)) as $field => $value) {
            $text = is_scalar($value) ? mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value))) : '';
            $text = $field === 'status' ? ($text === 'active' ? 'active' : 'inactive') : $text;
            $out[$field] = substr(hash_hmac('sha256', $text, $salt), 0, 16);
        }

        return $out;
    }

    /** @return list<array{app: string, tenant: string, base_url: string}>|null the linked apps that take `person`; null when FBS Suites did not answer */
    private static function peers(int $companyId): ?array
    {
        try {
            $res = self::hub()->get(FbsId::issuer().'/api/fbs-id/connector-peers', ['tenant' => (string) $companyId]);
        } catch (Throwable) {
            return null;
        }
        if (! $res->successful() || $res->json('success') !== true) {
            return null;
        }
        $peers = [];
        foreach ((array) $res->json('data.peers', []) as $p) {
            if (is_array($p) && is_string($p['app'] ?? null) && is_string($p['tenant'] ?? null) && is_string($p['base_url'] ?? null)
                && in_array('person', (array) ($p['types'] ?? []), true)) {
                $peers[] = ['app' => $p['app'], 'tenant' => $p['tenant'], 'base_url' => $p['base_url']];
            }
        }

        return $peers;
    }

    private static function hub(): PendingRequest
    {
        return Http::timeout(8)->acceptJson()->withBasicAuth(FbsId::clientId(), FbsId::clientSecret());
    }
}
