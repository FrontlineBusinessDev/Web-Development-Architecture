<?php

namespace App\FbsSync;

use App\Models\FbsChange;
use App\Models\FbsVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Takes another app's changes to shared records for the current company (contract fbs-record.v1). Per field the later `changed_at`
 * wins (a tie goes to the type's center app); a change already taken is `unchanged`; a record People does not have is `held`.
 * People is the `person` center: a change it takes is passed on to the other linked apps (forward_status), under People's own id.
 * A save made here is never logged as People's own change (the loop guard).
 */
final class ApplyChanges
{
    public const CENTERS = ['person' => 'people', 'time' => 'people', 'customer' => 'connect', 'money_item' => 'connect', 'product' => 'stock', 'work_item' => 'work'];

    /** The types People takes. */
    public const TYPES = ['person'];

    public const APP = 'people';

    /**
     * @param  list<mixed>  $changes
     * @return array<string, string> change_id => accepted | unchanged | superseded | held | refused
     */
    public static function run(array $changes, string $from, string $scopeType): array
    {
        $answers = [];
        foreach ($changes as $i => $c) {
            $id = is_array($c) && is_string($c['change_id'] ?? null) ? $c['change_id'] : "row {$i}";
            $answers[$id] = self::valid($c, $scopeType) ? self::one($c, $from) : 'refused';
        }

        return $answers;
    }

    private static function valid(mixed $c, string $scopeType): bool
    {
        return is_array($c) && is_string($c['change_id'] ?? null) && strlen($c['change_id']) <= 26 && ($c['type'] ?? null) === $scopeType
            && in_array($scopeType, self::TYPES, true) && is_string($c['external_id'] ?? null) && is_array($c['fields'] ?? null)
            && is_string($c['changed_at'] ?? null) && strtotime($c['changed_at']) !== false;
    }

    /** @param  array<string, mixed>  $c */
    private static function one(array $c, string $from): string
    {
        if (FbsChange::where('change_id', $c['change_id'])->exists()) {
            return 'unchanged';
        }
        $at = Carbon::parse($c['changed_at']);
        $e = PersonType::find($c['external_id']);
        $status = $e === null ? 'held' : 'superseded';
        $externalId = $e === null ? $c['external_id'] : PersonType::externalId($e);

        if ($e !== null) {
            $version = FbsVersion::firstOrNew(['type' => $c['type'], 'external_id' => $externalId], ['versions' => []]);
            $versions = $version->versions;
            $take = [];
            foreach (array_intersect_key($c['fields'], array_flip(PersonType::FIELDS)) as $field => $value) {
                $mine = isset($versions[$field]) ? Carbon::parse($versions[$field]['at']) : null;
                if ($mine === null || $at->gt($mine) || ($at->eq($mine) && $from === self::CENTERS[$c['type']])) {
                    $take[$field] = $value;
                    $versions[$field] = ['at' => $at->toIso8601ZuluString('microsecond'), 'change_id' => $c['change_id']];
                }
            }
            if ($take !== []) {
                try {
                    DB::transaction(function () use ($e, $take, $version, $versions) {
                        PersonType::apply($e, $take);
                        self::receiving(fn () => $e->save());
                        $version->forceFill(['versions' => $versions])->save();
                    });
                } catch (Throwable $x) {
                    report($x);

                    return 'refused';
                }
                $status = 'accepted';
            }
        }

        FbsChange::create(['change_id' => $c['change_id'], 'type' => $c['type'], 'external_id' => $externalId,
            'fields' => $status === 'accepted' ? array_intersect_key($c['fields'], array_flip(PersonType::FIELDS)) : $c['fields'],
            'changed_at' => $at, 'changed_by' => (array) ($c['changed_by'] ?? []), 'origin_app' => (string) ($c['origin']['app'] ?? $from),
            'received_from' => $from, 'status' => $status,
            'forward_status' => $status === 'accepted' && self::CENTERS[$c['type']] === self::APP ? 'pending' : null]);

        return $status;
    }

    /** Runs a save made from another app's change: the change log leaves it alone (never sent back). */
    public static function receiving(callable $save): mixed
    {
        Context::addHidden('fbs_sync.receiving', true);
        try {
            return $save();
        } finally {
            Context::forgetHidden('fbs_sync.receiving');
        }
    }

    public static function isReceiving(): bool
    {
        return (bool) Context::getHidden('fbs_sync.receiving');
    }
}
