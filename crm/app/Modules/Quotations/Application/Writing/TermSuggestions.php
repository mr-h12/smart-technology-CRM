<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Quotations\Domain\Listing\InvalidQuotationListQuery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Module 7, Point 6.8 — SmartTermInput's table (Step 6 Q5), both halves: every
 * saved quotation leaves its non-empty terms in `user_term_suggestions` for
 * the person who saved it, and the builder reads them back, own rows only,
 * newest first, at most twenty. `Design System §6.3`: suggestions, never a
 * structure the person did not type.
 *
 * Called inside `CreateQuotation`'s and `UpdateQuotation`'s transaction: a
 * quotation that does not save remembers nothing, and a remembered term is
 * one that was actually saved. One `upsert` on the table's `(user_id, field,
 * term)` UNIQUE — a repeat touches `last_used_at`, a new term inserts.
 *
 * The query builder rather than a directory interface: three columns, one
 * statement, no read — `AddListEntry` (Module 2) is the precedent for an
 * Application class that owns a table this small.
 */
final readonly class TermSuggestions
{
    private const FIELDS = ['payment_terms', 'warranty', 'delivery_terms'];

    /** Matches the column; a longer term is not a suggestion and is skipped. */
    private const MAX_LENGTH = 500;

    public const LIMIT = 20;

    public function __construct(private ConnectionInterface $connection) {}

    /** @param  array<string, mixed>  $validated  the quotation body as validated */
    public function remember(array $validated, string $actorId): void
    {
        $now = now();
        $rows = [];

        foreach (self::FIELDS as $field) {
            $term = $validated[$field] ?? null;

            if (! is_string($term) || trim($term) === '' || mb_strlen($term) > self::MAX_LENGTH) {
                continue;
            }

            $rows[] = [
                'id' => Str::uuid7()->toString(),
                'user_id' => $actorId,
                'field' => $field,
                'term' => $term,
                'last_used_at' => $now,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->connection->table('user_term_suggestions')
            ->upsert($rows, ['user_id', 'field', 'term'], ['last_used_at', 'updated_by', 'updated_at']);
    }

    /**
     * `GET /user-term-suggestions?field=`. An unknown `field` is `OpenAPI
     * §6.2`'s `400 invalid_request` — the same exception the list uses, since
     * the reader must not ignore a parameter it does not offer.
     *
     * @return list<array{term: string}> the term alone — the builder shows it and nothing else
     *
     * @throws InvalidQuotationListQuery
     */
    public function forField(mixed $field, string $actorId): array
    {
        if (! is_string($field) || ! in_array($field, self::FIELDS, true)) {
            throw InvalidQuotationListQuery::of('field', 'unknown_field');
        }

        $terms = $this->connection->table('user_term_suggestions')
            ->where('user_id', $actorId)
            ->where('field', $field)
            ->whereNull('deleted_at')
            ->orderByDesc('last_used_at')
            ->limit(self::LIMIT)
            ->pluck('term');

        $rows = [];

        foreach ($terms as $term) {
            $rows[] = ['term' => is_string($term) ? $term : ''];
        }

        return $rows;
    }
}
