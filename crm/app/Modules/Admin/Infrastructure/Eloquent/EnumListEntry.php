<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One `enum_lists` row, as persistence and nothing more.
 *
 * `Domain\Reference\ListEntry` is the framework-free object the rest of the
 * system speaks; Domain has an empty deptrac ruleset, so the Eloquent side has
 * to live here. This class maps a table and stops.
 *
 * @property string $id
 * @property string $list
 * @property string $code
 * @property string $label_en
 * @property string $label_ar
 * @property int $position
 */
final class EnumListEntry extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'enum_lists';

    /** @var list<string> */
    protected $fillable = ['list', 'code', 'label_en', 'label_ar', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
