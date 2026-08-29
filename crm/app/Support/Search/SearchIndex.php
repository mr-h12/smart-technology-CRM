<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * What is searchable, declared once.
 *
 * **An enum rather than a string.** The build plan writes the call as
 * `SearchService.search(index, query, filters)` without saying what `index` is,
 * and a string would make an unknown index a runtime failure inside the driver.
 * A case cannot be wrong, and both drivers — this one and Meilisearch's — read
 * the same declaration instead of each carrying a private map.
 *
 * **One case, because one table exists.** Catalog, Suppliers and Deals add
 * theirs when their modules land; `CLAUDE.md` forbids speculative scaffolding,
 * and an index for a table that does not exist cannot be tested.
 */
enum SearchIndex: string
{
    case Customers = 'customers';

    public function table(): string
    {
        return $this->value;
    }

    /**
     * The columns a person's words are matched against.
     *
     * ⚠️ **`name` alone, and that is a floor rather than a judgement about what
     * is useful.** No source enumerates the searchable fields: §6.2's example is
     * `?q=ahmed`, §14.2 describes Meilisearch as "Arabic search with hamza, taa
     * marbuta and yaa normalisation", and §4.2 makes `name` the identifying
     * field — `D-35` compares names and nothing else. Adding `contact_person`,
     * `phone` or `email` would each be a product decision nobody has recorded,
     * and each is one line here when somebody makes it. Recorded as an open
     * owner question in `CHECKLIST.md`.
     *
     * @return non-empty-list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Customers => ['name'],
        };
    }

    /**
     * The columns a caller may narrow by, closed on purpose.
     *
     * A filter key is a column name that reaches SQL, and a key cannot be bound
     * the way a value can — so the set is an allowlist rather than a check that
     * the column happens to exist.
     *
     * Both entries earn their place from a documented rule rather than from
     * anticipation: §3.3 scopes **every** customer read by owner, and Flow 7
     * hides an archived customer from the list. A capped search that ignored
     * either would return the wrong page of results rather than fewer of the
     * right ones — which is why the narrowing has to happen inside the search,
     * not after it.
     *
     * @return list<string>
     */
    public function filterable(): array
    {
        return match ($this) {
            self::Customers => ['sales_owner_id', 'is_archived'],
        };
    }
}
