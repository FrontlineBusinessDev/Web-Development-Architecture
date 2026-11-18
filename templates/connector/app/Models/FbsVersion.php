<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * When each shared field of one record was last set, and by which change (contract fbs-record.v1): the later change wins.
 *
 * @property string $type
 * @property string $external_id
 * @property array<string, array{at: string, change_id: string}> $versions
 */
class FbsVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'app_fbs_versions';

    protected $fillable = ['type', 'external_id', 'versions'];

    protected function casts(): array
    {
        return ['versions' => 'array'];
    }
}
