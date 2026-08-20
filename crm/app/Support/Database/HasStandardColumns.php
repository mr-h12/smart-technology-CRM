<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The model half of the standard column block.
 *
 * HasUuids supplies D-61's key: Laravel 13 generates UUID v7 from it, which is
 * time-ordered and monotonic. SoftDeletes supplies DB-01.
 *
 * created_by and updated_by are deliberately not filled here. Who performed an
 * action is decided by the request, and a model observer guessing it would be
 * wrong in exactly the cases that matter — queue workers, scheduled jobs, and
 * anything running without a session. Module 0 step 6 wires the actor in as part
 * of the audit layer, where the request context is already established.
 */
trait HasStandardColumns
{
    use HasUuids;
    use SoftDeletes;
}
