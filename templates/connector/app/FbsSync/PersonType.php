<?php

namespace App\FbsSync;

use App\FbsPay\EmployeeRow;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\Statuses;

/**
 * The shared `person` fields (contract fbs-record.v1) on People's Employee. A department or position is matched by name and never made
 * here (the org chart stays HR's). Every other field (pay, government ids, bank, 201 file) stays People's own.
 */
final class PersonType
{
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'mobile', 'employee_number', 'department', 'position',
        'employment_type', 'date_hired', 'status'];

    /** The shared id the other apps know this person by. */
    public static function externalId(Employee $e): string
    {
        return EmployeeRow::externalId($e);
    }

    /** People's own id (`people:{company}:{employee}`, this company only), else the id FBS Learn sent the person with. */
    public static function find(string $externalId): ?Employee
    {
        if (preg_match('/^people:(\d+):(\d+)$/', $externalId, $m)) {
            return (int) $m[1] === (int) Company::currentId() ? Employee::find((int) $m[2]) : null;
        }

        return Employee::where('learn_external_id', $externalId)->first();
    }

    /** @return array<string, mixed> */
    public static function fields(Employee $e): array
    {
        return [
            'first_name' => $e->first_name, 'middle_name' => $e->middle_name, 'last_name' => $e->last_name, 'email' => $e->email,
            'mobile' => $e->mobile, 'employee_number' => $e->employee_number,
            'department' => $e->department_id ? Department::whereKey($e->department_id)->value('name') : null,
            'position' => $e->position_id ? Position::whereKey($e->position_id)->value('title') : null,
            'employment_type' => $e->employment_type, 'date_hired' => $e->hired_on?->toDateString(),
            'status' => $e->status === Statuses::ACTIVE ? 'active' : 'inactive',
        ];
    }

    /** @param  array<string, mixed>  $fields */
    public static function apply(Employee $e, array $fields): void
    {
        foreach (array_intersect_key($fields, array_flip(self::FIELDS)) as $field => $value) {
            match ($field) {
                'department' => $e->department_id = self::id(Department::whereRaw('lower(name) = ?', [self::lower($value)])->value('id')) ?? $e->department_id,
                'position' => $e->position_id = self::id(Position::whereRaw('lower(title) = ?', [self::lower($value)])->value('id')) ?? $e->position_id,
                'date_hired' => $e->hired_on = $value,
                'status' => $e->status = $value === 'active' ? Statuses::ACTIVE : Statuses::INACTIVE,
                default => $e->setAttribute($field, $value),
            };
        }
    }

    private static function lower(mixed $name): string
    {
        return is_string($name) ? mb_strtolower(trim($name)) : '';
    }

    /** @return int<0, max>|null a department or position id People already has (never made here) */
    private static function id(mixed $id): ?int
    {
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
