<?php

namespace App\FbsSync;

use App\FbsId\FbsId;
use App\Models\Employee;
use App\Models\FbsChange;
use App\Models\FbsLearnConnection;
use App\Models\FbsLink;
use App\Models\FbsPayConnection;
use Illuminate\Support\Str;

/**
 * Which other FBS apps share a person (rule 72), read from People's own records only, never by calling another app: the FBS Pay
 * connector (approved for Pay, connection on), the FBS Learn connector (`learn_external_id`, connection live or paused), another
 * app's id kept by auto-link (`app_fbs_links`), and the shared-record changes (where they came from, the apps that took one).
 */
final class Connected
{
    /** The FBS apps a person can be shared with, in the order the confirm names them. */
    public const APPS = ['pay', 'learn', 'work', 'stock', 'connect'];

    /** An answer that means the other app has the record (a held or refused change is not linked there). */
    private const LINKED = ['accepted', 'unchanged', 'superseded', 'locked'];

    /** @return list<string> app keys, e.g. ['pay', 'learn'] */
    public static function apps(Employee $e): array
    {
        return self::forMany([$e])[$e->id] ?? [];
    }

    /**
     * @param  iterable<Employee>  $employees
     * @return array<int, list<string>> employee id => app keys (only the connected ones are listed)
     */
    public static function forMany(iterable $employees): array
    {
        $employees = collect($employees);
        if ($employees->isEmpty()) {
            return [];
        }
        $payOn = FbsPayConnection::whereIn('status', ['connected', 'broken'])->exists();
        $learnOn = FbsLearnConnection::whereIn('status', FbsLearnConnection::LINKED)->exists();
        $apps = [];
        $byExternal = [];
        foreach ($employees as $e) {
            $apps[$e->id] = [];
            if ($payOn && $e->fbs_pay_review === 'approved') {
                $apps[$e->id][] = 'pay';
            }
            if ($learnOn && $e->learn_external_id) {
                $apps[$e->id][] = 'learn';
            }
            $byExternal[PersonType::externalId($e)] = $e->id;
            if ($e->learn_external_id) {
                $byExternal[$e->learn_external_id] = $e->id;
            }
        }
        if (FbsId::enabled()) {
            FbsLink::whereIn('employee_id', $employees->pluck('id'))->get(['external_id', 'employee_id'])->each(function (FbsLink $l) use (&$apps, &$byExternal) {
                $apps[$l->employee_id][] = Str::before($l->external_id, ':');
                $byExternal[$l->external_id] = $l->employee_id;
            });
            FbsChange::whereIn('external_id', array_keys($byExternal))->where('type', 'person')
                ->get(['external_id', 'origin_app', 'received_from', 'targets'])
                ->each(function (FbsChange $c) use ($byExternal, &$apps) {
                    $id = $byExternal[$c->external_id];
                    array_push($apps[$id], $c->origin_app, (string) $c->received_from);
                    foreach ($c->targets ?? [] as $key => $answer) {
                        if (in_array($answer, self::LINKED, true)) {
                            $apps[$id][] = Str::before((string) $key, ':');
                        }
                    }
                });
        }

        return array_filter(array_map(fn (array $list) => array_values(array_intersect(self::APPS, $list)), $apps));
    }
}
