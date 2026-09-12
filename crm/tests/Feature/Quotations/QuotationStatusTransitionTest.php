<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module 7, Point 4.1 — §6.1's nine statuses and §6.4's arrows as one edge
 * table.
 *
 * Extends PHPUnit's own `TestCase` on `PricedLineTest`'s precedent: a pure
 * Domain class, no database, no application boot.
 *
 * The first provider transcribes the table in `CHECKLIST.md` 4.1 row by row,
 * so the test and the checklist can be read against each other; the second
 * lists every status that is *not* the source of a drawn arrow, so a stray
 * edge on a terminal status fails here rather than in Module 10.
 */
final class QuotationStatusTransitionTest extends TestCase
{
    // ───────────────────────────────── the edge table

    /** @return iterable<string, array{string, string}> */
    public static function drawnEdges(): iterable
    {
        yield 'draft → pending (§6.4 submit)' => ['draft', 'pending'];
        yield 'pending → approved (§6.4 approve, edit + approve)' => ['pending', 'approved'];
        yield 'pending → draft (§6.4 return with note; Q1)' => ['pending', 'draft'];
        yield 'approved → sent (§6.4 send)' => ['approved', 'sent'];
        yield 'sent → accepted (§6.1)' => ['sent', 'accepted'];
        yield 'sent → partial (§6.1)' => ['sent', 'partial'];
        yield 'sent → counter (§6.1)' => ['sent', 'counter'];
        yield 'sent → rejected (§6.1)' => ['sent', 'rejected'];
        yield 'sent → expired (§6.1, J-01)' => ['sent', 'expired'];
    }

    #[DataProvider('drawnEdges')]
    public function test_every_drawn_arrow_is_allowed(string $from, string $to): void
    {
        self::assertTrue(QuotationStatusTransition::isAllowed($from, $to));
        self::assertContains($to, QuotationStatusTransition::allowedFrom($from));
    }

    public function test_the_table_has_exactly_the_drawn_arrows_and_no_others(): void
    {
        // The whole graph in one assertion, so an edge added anywhere without
        // a row in `drawnEdges()` fails here.
        $expected = [];
        foreach (self::drawnEdges() as [$from, $to]) {
            $expected[$from][] = $to;
        }

        foreach ($expected as $from => $targets) {
            self::assertSame($targets, QuotationStatusTransition::allowedFrom($from), $from);
        }
    }

    // ───────────────────────────────── terminals

    /** @return iterable<string, array{string}> */
    public static function terminalStatuses(): iterable
    {
        // §6.3: Partial and Counter continue through a *copy*, not an edge.
        yield 'accepted' => ['accepted'];
        yield 'partial' => ['partial'];
        yield 'counter' => ['counter'];
        yield 'rejected' => ['rejected'];
        yield 'expired' => ['expired'];
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_status_has_no_edge(string $status): void
    {
        self::assertSame([], QuotationStatusTransition::allowedFrom($status));
    }

    // ───────────────────────────────── refusals

    public function test_sent_cannot_go_back_to_draft(): void
    {
        // §10.3: from `sent` the document is a fixed snapshot; the way forward
        // is a new version (Point 4.3), never an edit of this row.
        self::assertFalse(QuotationStatusTransition::isAllowed('sent', 'draft'));
    }

    public function test_an_unknown_status_has_no_edge(): void
    {
        self::assertFalse(QuotationStatusTransition::isAllowed('returned', 'draft'));
        self::assertSame([], QuotationStatusTransition::allowedFrom('returned'));
    }

    public function test_the_refusal_maps_to_openapi_5_1s_state_transition_invalid_row(): void
    {
        $refused = QuotationWriteRefused::invalidTransition('sent', 'draft');

        self::assertSame(QuotationWriteRefused::INVALID_TRANSITION, $refused->reason);
        self::assertSame(409, $refused->status);
        self::assertSame('state_transition_invalid', $refused->errorCode);
        self::assertSame('status', $refused->field);
        self::assertNull($refused->currentEtag);
        // The pair is for the log line, not the envelope — the renderer reads
        // the lang key by reason and never `getMessage()`.
        self::assertSame('Quotation write refused: invalid_transition (sent → draft)', $refused->getMessage());
    }
}
