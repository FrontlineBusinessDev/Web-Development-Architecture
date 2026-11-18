<?php

namespace App\FbsSync;

use App\FbsId\FbsId;
use App\Jobs\PushFbsChanges;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FbsChange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records People's own edits to shared `person` fields as changes to send (contract fbs-record.v1), and queues the push a minute
 * later. A save made from another app's change is never recorded (the loop guard). Nothing while FBS ID is off, nor for a sandbox.
 */
final class ChangeLog
{
    public static function saved(Employee $e): void
    {
        if (ApplyChanges::isReceiving() || ! FbsId::enabled() || Company::query()->whereKey($e->company_id)->whereNotNull('sandbox_expires_at')->exists()) {
            return;
        }
        $now = PersonType::fields($e);
        $before = $e->wasRecentlyCreated ? [] : PersonType::fields((new Employee)->forceFill(array_intersect_key($e->getRawOriginal(),
            array_flip(['first_name', 'middle_name', 'last_name', 'email', 'mobile', 'employee_number', 'department_id', 'position_id', 'employment_type', 'hired_on', 'status']))));
        $changed = array_filter($now, fn ($value, $field) => ($before[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }

        $user = Auth::user();
        (new FbsChange)->forceFill(['company_id' => $e->company_id, 'change_id' => (string) Str::ulid(), 'type' => 'person', 'external_id' => PersonType::externalId($e),
            'fields' => $changed, 'changed_at' => now(), 'origin_app' => $e->learn_external_id ? 'learn' : ApplyChanges::APP, 'received_from' => null, 'status' => 'pending',
            'changed_by' => ['app' => ApplyChanges::APP, 'first_name' => (string) ($user->first_name ?? 'FBS'), 'last_name' => (string) ($user->last_name ?? 'People')]])->save();

        PushFbsChanges::dispatch($e->company_id)->delay(now()->addMinute())->afterCommit();
    }
}
