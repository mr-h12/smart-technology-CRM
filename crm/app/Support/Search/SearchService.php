<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * The seam `D-48` requires — "Meilisearch comes last, behind an abstraction
 * layer built on day one".
 *
 * `MVP_Build_Plan_EN.md` states the condition this interface exists to meet:
 * without the layer, adding Meilisearch at the end means **rewriting every
 * screen with a search bar**. `CLAUDE.md` repeats it — "all search calls must
 * use it so Meilisearch can replace the driver later".
 *
 * ── Why it answers with identifiers ────────────────────────────────────────
 *
 * Because that is the shape Meilisearch imposes: it searches its own index and
 * returns document ids, which the caller hydrates from PostgreSQL. A contract
 * that returned rows could be met by an ILIKE driver and **not** by a
 * Meilisearch one, which would make the swap a rewrite — the exact outcome
 * `D-48` is guarding against. The shape is chosen now rather than discovered in
 * Module 15.
 *
 * ── Why it lives in `Support` ──────────────────────────────────────────────
 *
 * On `SettingReader`'s terms. `deptrac.modules.yaml` gives every module an empty
 * ruleset — "no module may depend on any other" — and search is consumed by
 * Customers, Catalog, Suppliers and Deals alike. A contract in `app/Support` lets
 * all of them ask without learning each other's names.
 *
 * ── What a caller may pass ─────────────────────────────────────────────────
 *
 * Words a person typed, never a pattern. `OpenAPI_Contract_EN.md` §6.2: `q`
 * "always passes through `SearchService`; do not expose a database-specific
 * search syntax". A driver therefore treats every character as a character —
 * see `PostgresSearchDriver`'s escaping, which is the load-bearing part of it.
 */
interface SearchService
{
    /**
     * The ids matching $query in $index, narrowed by $filters.
     *
     * @param  array<string, string|int|bool|null>  $filters  keys must be
     *                                                        declared by the index (`SearchIndex::filterable()`)
     * @return list<string>
     *
     * @throws \InvalidArgumentException on a blank query or an undeclared filter
     */
    public function search(SearchIndex $index, string $query, array $filters = []): array;
}
