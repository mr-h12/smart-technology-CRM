<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Support\Search\ArabicNormalisation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 3, Point 2.2 — the normalisation §10.2 and §14.2 both name.
 *
 * ── Exactly three families, because exactly three are named ────────────────
 *
 * §10.2: "fuzzy match with normalisation of **hamza, taa marbuta and yaa**
 * forms". §14.2 says the same of search. Two sections, the same three, and
 * nothing else — so diacritics and tatweel are **not** folded here, however
 * common that is elsewhere. Recorded as an open owner question in
 * `CHECKLIST.md` rather than added quietly.
 *
 * ── One rule, two languages, and a test that they agree ────────────────────
 *
 * The query is normalised in PHP and the stored column in SQL, because
 * normalising every row in PHP would mean reading every row. That is two
 * implementations of one rule, which is the defect waiting to happen — so the
 * mapping lives in **one** constant and the SQL is built from it, and
 * {@see test_that_the_database_and_php_agree} runs both over the same strings
 * and compares. A rule that drifts between the two would let a name match on
 * one side and not the other.
 */
final class ArabicNormalisationTest extends TestCase
{
    /** @return array<string, array{string, string}> — the keys are the data-set names PHPUnit prints. */
    public static function pairs(): array
    {
        return [
            'alef with hamza above' => ['أحمد', 'احمد'],
            'alef with hamza below' => ['إبراهيم', 'ابراهيم'],
            'alef with madda' => ['آمنة', 'امنه'],
            'alef wasla' => ['ٱحمد', 'احمد'],
            'taa marbuta' => ['فاطمة', 'فاطمه'],
            'alef maksura' => ['ليلى', 'ليلي'],
            'several at once' => ['آية إسلام مصطفى', 'ايه اسلام مصطفي'],
        ];
    }

    #[DataProvider('pairs')]
    public function test_that_a_written_form_folds_to_its_plain_one(string $written, string $plain): void
    {
        self::assertSame($plain, ArabicNormalisation::normalise($written));
    }

    /** Text with none of the three forms comes back untouched. */
    public function test_that_other_text_is_left_alone(): void
    {
        foreach (['Ahmed Hassan', 'محمد حسن', '507 Supplies', ''] as $text) {
            self::assertSame($text, ArabicNormalisation::normalise($text));
        }
    }

    /** Normalising an already-normalised name changes nothing. */
    #[DataProvider('pairs')]
    public function test_that_normalising_twice_is_the_same_as_once(string $written, string $plain): void
    {
        self::assertSame($plain, ArabicNormalisation::normalise(ArabicNormalisation::normalise($written)));
    }

    /**
     * `translate()` needs the two sides to have the same number of characters,
     * or PostgreSQL silently drops the extras.
     */
    public function test_that_the_two_sides_of_the_mapping_are_the_same_length(): void
    {
        self::assertSame(
            mb_strlen(ArabicNormalisation::FROM),
            mb_strlen(ArabicNormalisation::TO),
            'A shorter TO makes translate() delete characters instead of replacing them.',
        );
    }

    /** The whole point: the query and the column are folded by the same rule. */
    #[DataProvider('pairs')]
    public function test_that_the_database_and_php_agree(string $written, string $plain): void
    {
        $inSql = DB::scalar('select translate(?, ?, ?)', [
            $written,
            ArabicNormalisation::FROM,
            ArabicNormalisation::TO,
        ]);

        self::assertSame($plain, $inSql, 'PostgreSQL folded this name differently from PHP.');
        self::assertSame(ArabicNormalisation::normalise($written), $inSql);
    }
}
