<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * The folding §10.2 and §14.2 both require, in one place.
 *
 * §10.2 describes the duplicate check as a "fuzzy match with normalisation of
 * **hamza, taa marbuta and yaa** forms"; §14.2 says the same of search. The two
 * uses are different — one warns about a similar customer, the other finds one
 * — and the rule is identical, so it is written once.
 *
 * ── Exactly three families, and nothing else ───────────────────────────────
 *
 * ⚠️ Diacritics (`َ ُ ِ ّ ْ`) and tatweel (`ـ`) are **not** folded, however usual
 * that is in Arabic text handling. Two sections name three families each and
 * agree on which three; adding a fourth would be a product decision nobody has
 * recorded, and it would change which customers the system calls duplicates.
 * Recorded as an open owner question — and it is one line here when answered.
 *
 * ── Why the mapping is a pair of strings ───────────────────────────────────
 *
 * PostgreSQL's `translate(text, from, to)` folds the stored column, because
 * normalising every row in PHP would mean reading every row. So the same rule
 * has to hold in both languages, and the way to guarantee that is to keep **one**
 * declaration and let each side consume it: PHP walks the two strings, and
 * `PostgresSearchDriver` binds them straight into `translate()`.
 * `ArabicNormalisationTest` runs both over the same names and compares.
 */
final class ArabicNormalisation
{
    /**
     * The written forms, in the order their replacements appear in {@see TO}.
     *
     * `أ` `إ` `آ` `ٱ` — the hamza-bearing and wasla alefs · `ة` taa marbuta ·
     * `ى` alef maksura.
     */
    public const FROM = 'أإآٱةى';

    /** `ا` `ا` `ا` `ا` · `ه` · `ي` — character for character with {@see FROM}. */
    public const TO = 'اااا'.'هي';

    /**
     * The same fold PostgreSQL's `translate()` performs, in PHP.
     *
     * Character by character rather than `str_replace`, because these are
     * multi-byte and a byte-wise replacement would corrupt neighbouring
     * letters. `mb_str_split` is what makes the two sides comparable.
     */
    public static function normalise(string $text): string
    {
        $from = mb_str_split(self::FROM);
        $to = mb_str_split(self::TO);

        $folded = '';

        foreach (mb_str_split($text) as $character) {
            $at = array_search($character, $from, true);

            $folded .= $at === false ? $character : $to[$at];
        }

        return $folded;
    }
}
