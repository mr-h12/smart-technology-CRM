<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

/**
 * Module 10 · 2.2 — `GET /purchase-orders`' query (`OpenAPI §6`): `page`,
 * `per_page`, `q` and `sort`, nothing else (the owner: "no filter besides
 * `q`"). Parsed with `QuotationListCriteria`'s parsers, whose 400 it shares.
 */
final readonly class PurchaseOrderListCriteria
{
    public const ALLOWED_PARAMETERS = ['page', 'per_page', 'q', 'sort'];

    /** The owner's three; the default is `-created_at`, newest first. */
    public const ALLOWED_SORTS = ['po_date', 'po_number', 'created_at'];

    /**
     * @param  list<array{field: string, descending: bool}>  $sorts
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = QuotationListCriteria::DEFAULT_PER_PAGE,
        public ?string $q = null,
        public array $sorts = [['field' => 'created_at', 'descending' => true]],
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  the raw query string, as received
     *
     * @throws InvalidQuotationListQuery
     */
    public static function fromQuery(array $query): self
    {
        QuotationListCriteria::refuseUnknownParameters($query, self::ALLOWED_PARAMETERS);

        $page = QuotationListCriteria::positiveInteger($query['page'] ?? null, 'page', 1);
        $perPage = QuotationListCriteria::positiveInteger($query['per_page'] ?? null, 'per_page', QuotationListCriteria::DEFAULT_PER_PAGE);

        // §6.1: "maximum 100 … Invalid or excessive values return 400".
        if ($perPage > QuotationListCriteria::MAX_PER_PAGE) {
            throw InvalidQuotationListQuery::of('per_page', 'above_maximum');
        }

        $q = $query['q'] ?? null;
        if ($q !== null && ! is_string($q)) {
            throw InvalidQuotationListQuery::of('q', 'not_a_string');
        }

        return new self(
            page: $page,
            perPage: $perPage,
            // A blank `q` asks for everything; `SearchService` refuses a blank query.
            q: $q === null || trim($q) === '' ? null : trim($q),
            sorts: QuotationListCriteria::sortKeys($query['sort'] ?? null, self::ALLOWED_SORTS, 'created_at'),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
