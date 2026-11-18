<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change of a shared record (contract fbs-record.v1): made in People (`received_from` null) or taken from another app, which People,
 * the `person` center, passes on to the other linked apps (`forward_status`).
 *
 * @property int $company_id
 * @property string $change_id
 * @property string $type
 * @property string $external_id
 * @property array<string, mixed> $fields
 * @property Carbon $changed_at
 * @property array{app: string, first_name: string, last_name: string} $changed_by
 * @property string $origin_app
 * @property string|null $received_from
 * @property string $status accepted|superseded|held|refused|pending|none
 * @property string|null $forward_status pending|accepted|none
 * @property array<string, string>|null $targets
 */
class FbsChange extends Model
{
    use BelongsToCompany;

    protected $table = 'app_fbs_changes';

    protected $fillable = ['change_id', 'type', 'external_id', 'fields', 'changed_at', 'changed_by', 'origin_app', 'received_from', 'status', 'forward_status', 'targets'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'changed_by' => 'array', 'targets' => 'array', 'changed_at' => 'datetime'];
    }
}
