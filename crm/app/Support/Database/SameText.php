<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `D-94`'s duplicate comparison, written once (F-20 · 1.4): the stored column
 * and the value both trimmed and case-folded by the database. `lower()` here
 * folds more than ASCII (`lower('ÉCOLE')` is `école`, measured 2026-09-27) and
 * leaves Arabic, which has no case, as it is. `=` rather than `ILIKE`, so a `%`
 * or `_` in a literal value is never a wildcard.
 *
 * Customers, Suppliers and Catalog ask it, each of its own column. The column
 * is interpolated into the SQL, so it is typed `literal-string`: PHPStan
 * refuses any caller that passes input.
 */
final class SameText
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     * @return Builder<TModel>
     */
    public static function where(Builder $query, string $column, string $value): Builder
    {
        return $query->whereRaw("lower(btrim({$column})) = lower(?)", [trim($value)]);
    }
}
