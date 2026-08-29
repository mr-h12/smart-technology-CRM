<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The framework binding for the table Point 1.2 built.
 *
 * No path column and no scan status: the uploaded file is parsed and dropped,
 * which is Point 1.2's recorded decision and the reason this import does not
 * pull in `SEC-15`'s scanner or `D-38`'s permission-checked download.
 *
 * @property string $id
 * @property string $original_filename
 * @property int $row_count
 * @property int $imported_count
 * @property int $incomplete_count
 * @property string|null $created_by
 * @property string|null $updated_by
 */
#[Fillable(['original_filename', 'row_count', 'imported_count', 'incomplete_count'])]
class ImportBatch extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'import_batches';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'imported_count' => 'integer',
            'incomplete_count' => 'integer',
        ];
    }
}
