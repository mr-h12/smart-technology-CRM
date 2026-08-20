<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * The numeric precisions D-68 fixes, in one place.
 *
 * They live here rather than as literals in migrations because the column scale
 * and the model cast scale have to agree. As two numbers in two files they
 * drift, and the symptom of drift is a value that is stored exactly and then
 * read back rounded — which looks like a calculation bug and is not.
 *
 * DB-07 forbids float anywhere near a price. These are NUMERIC in the database
 * and decimal strings in PHP; arithmetic goes through BCMath.
 */
final class Precision
{
    /**
     * Money: twelve integer digits and six decimals.
     *
     * Six matters because D-06 forbids rounding an intermediate value and D-65
     * lets rounding be switched off entirely, so a stored value has to survive
     * at working precision. On PO #226 the tax computes to 1021.263012.
     */
    public const MONEY_TOTAL = 18;

    public const MONEY_SCALE = 6;

    /** FX rates are quoted far below currency precision and multiply into every line. */
    public const FX_TOTAL = 18;

    public const FX_SCALE = 8;

    /** Percentages: 14.000, 20.500. Three decimals is more than the UI offers. */
    public const PERCENT_TOTAL = 6;

    public const PERCENT_SCALE = 3;

    /** Units include metre and kilo (§7.3), so a quantity is not an integer. */
    public const QUANTITY_TOTAL = 14;

    public const QUANTITY_SCALE = 4;

    /** Eloquent cast strings, so a model never restates a scale. */
    public const CAST_MONEY = 'decimal:'.self::MONEY_SCALE;

    public const CAST_FX = 'decimal:'.self::FX_SCALE;

    public const CAST_PERCENT = 'decimal:'.self::PERCENT_SCALE;

    public const CAST_QUANTITY = 'decimal:'.self::QUANTITY_SCALE;
}
