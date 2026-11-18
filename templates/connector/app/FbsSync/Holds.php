<?php

namespace App\FbsSync;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\FbsChange;
use App\Models\FbsLink;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The changes another FBS app sent for a person People could not match (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync item 6,
 * spec §10.1 #6: holds, never merges). HR links one to the person here (People keeps that app's id, App\Models\FbsLink, and applies the
 * change as if it just arrived) or ignores it. Company › Integrations › FBS apps and `propose_hold_decision` both come here.
 */
final class Holds
{
    /** @return Collection<int, FbsChange> the held changes, newest first */
    public static function waiting(int $limit = 100): Collection
    {
        return FbsChange::whereNotNull('received_from')->where('status', 'held')->orderByDesc('changed_at')->limit($limit)->get();
    }

    /**
     * Up to three people here who may be the one a held change is about: the same email, else the same first and last name.
     *
     * @return Collection<int, Employee>
     */
    public static function candidates(FbsChange $c): Collection
    {
        $m = is_array($c->match) ? $c->match : [];
        $q = Employee::withoutGlobalScope(BranchScope::class);
        $email = mb_strtolower(trim((string) ($m['email'] ?? '')));
        $first = mb_strtolower(trim((string) ($m['first_name'] ?? '')));
        $last = mb_strtolower(trim((string) ($m['last_name'] ?? '')));
        if ($email === '' && ($first === '' || $last === '')) {
            return new Collection;
        }

        return $q->where(fn ($w) => $w->when($email !== '', fn ($x) => $x->orWhereRaw('lower(email) = ?', [$email]))
            ->when($first !== '' && $last !== '', fn ($x) => $x->orWhere(fn ($y) => $y->whereRaw('lower(first_name) = ?', [$first])->whereRaw('lower(last_name) = ?', [$last]))))
            ->orderBy('last_name')->limit(3)->get();
    }

    /** Links the held change's person to $e and applies it; returns the answer (accepted, superseded, …). */
    public static function link(FbsChange $c, Employee $e, User $by): string
    {
        return DB::transaction(fn () => self::linkLocked(self::lockHeld($c), $e, $by));
    }

    private static function linkLocked(FbsChange $c, Employee $e, User $by): string
    {
        if (str_starts_with($c->external_id, 'people:')) {
            throw ValidationException::withMessages(['employee_id' => 'This change is for a person People made; it cannot be linked to someone else.']);
        }
        $taken = FbsLink::where('external_id', $c->external_id)->first();
        if ($taken !== null && $taken->employee_id !== $e->id) {
            throw ValidationException::withMessages(['employee_id' => 'That person in the other app is already linked to someone else here.']);
        }
        $taken ?? FbsLink::create(['external_id' => $c->external_id, 'employee_id' => $e->id]);
        $from = (string) $c->received_from;
        $answer = ApplyChanges::retry($c);
        AuditEvent::record('fbs_hold_linked', $e, ['app' => $from, 'external_id' => $c->external_id, 'fields' => array_keys((array) $c->fields), 'answer' => $answer], $by->id);

        return $answer;
    }

    /** Sets a held change aside: nothing is written, the row stays as `ignored`. */
    public static function ignore(FbsChange $c, User $by): void
    {
        DB::transaction(function () use ($c, $by) {
            $c = self::lockHeld($c);
            $c->forceFill(['status' => 'ignored'])->save();
            AuditEvent::record('fbs_hold_ignored', $c, ['app' => $c->received_from, 'external_id' => $c->external_id, 'fields' => array_keys((array) $c->fields)], $by->id);
        });
    }

    /** The change, re-read under a lock, so two people deciding at once never both win (rule 31). */
    private static function lockHeld(FbsChange $c): FbsChange
    {
        $fresh = FbsChange::whereKey($c->id)->lockForUpdate()->first();
        if ($fresh === null || $fresh->received_from === null || $fresh->status !== 'held') {
            throw ValidationException::withMessages(['change' => 'This change is no longer waiting: someone already decided it.']);
        }

        return $fresh;
    }
}
