<?php

namespace App\FbsSync;

use App\Models\FacultyMember;
use App\Models\NonFacultyMember;

/**
 * This school's side of the nightly tally (contract fbs-record.v1 "Tally"; fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync
 * Task 6): per staff member, under the id the other apps know them by, a salted hash of each shared field Learn holds, so the asking
 * app can tell which records and fields differ without any value crossing (rule 18). The salt is the asker's, new each run.
 */
final class TallyAnswer
{
    /** The shared fields every app compares (employment type is left out: each app words it its own way). */
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'mobile', 'employee_number', 'department', 'position', 'date_hired', 'status'];

    public const MAX = 5000;

    /** @return array{records: array<string, array<string, string>>, truncated: bool} */
    public static function person(string $salt): array
    {
        $records = [];
        foreach ([FacultyMember::class, NonFacultyMember::class] as $model) {
            foreach ($model::query()->orderBy('id')->limit(self::MAX)->get() as $m) {
                $records[PersonType::externalId($m)] = self::hashes(PersonType::fields($m), $salt);
            }
        }

        return ['records' => array_slice($records, 0, self::MAX, true), 'truncated' => count($records) > self::MAX];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string> field => the first 16 hex of HMAC-SHA256(normalized value, salt)
     */
    public static function hashes(array $fields, string $salt): array
    {
        $out = [];
        foreach (array_intersect_key($fields, array_flip(self::FIELDS)) as $field => $value) {
            $text = is_scalar($value) ? mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value))) : '';
            $text = $field === 'status' ? ($text === 'active' ? 'active' : 'inactive') : $text; // each app's own status words
            $out[$field] = substr(hash_hmac('sha256', $text, $salt), 0, 16);
        }

        return $out;
    }
}
