<?php

namespace App\FbsSync;

use App\Models\Department;
use App\Models\Position;

/**
 * A department or position another FBS app names for a person (FBS Learn's staff, a shared `person` change): People's own of that name
 * (case aside), else one made here with that name, so the person never arrives with "No department" (QA 2026-11-18 Scenario 1 #2).
 * HR renames, merges or archives them in Company › Structure; a made position has no pay range or budget.
 */
final class OrgNames
{
    /**
     * The department's id, or null when no name was sent; `$made` turns true when it was made here.
     *
     * @return int<1, max>|null
     */
    public static function department(mixed $name, bool &$made = false): ?int
    {
        $name = self::clean($name);
        if ($name === null) {
            return null;
        }
        $id = self::id(Department::whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id'));

        return $id ?? self::make(fn () => Department::createOrFirst(['name' => $name], ['status' => 'active']), $made);
    }

    /**
     * The position's id (a new one sits in the department sent with it), or null when no title was sent; `$made` as above.
     *
     * @return int<1, max>|null
     */
    public static function position(mixed $title, ?int $departmentId, bool &$made = false): ?int
    {
        $title = self::clean($title);
        if ($title === null) {
            return null;
        }
        $id = self::id(Position::whereRaw('lower(title) = ?', [mb_strtolower($title)])->value('id'));

        return $id ?? self::make(fn () => Position::createOrFirst(['title' => $title], ['department_id' => $departmentId, 'status' => 'active']), $made);
    }

    /**
     * @param  callable(): (Department|Position)  $create
     * @return int<1, max>|null
     */
    private static function make(callable $create, bool &$made): ?int
    {
        $row = $create();
        $made = $made || $row->wasRecentlyCreated;

        return self::id($row->id);
    }

    /** @return int<1, max>|null */
    private static function id(mixed $id): ?int
    {
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private static function clean(mixed $name): ?string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
