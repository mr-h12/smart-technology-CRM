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
 * **A case per table that exists.** Catalog and Deals add theirs when their
 * modules land; `CLAUDE.md` forbids speculative scaffolding, and an index for a
 * table that does not exist cannot be tested. Suppliers arrived with Module 4
 * Point 2.1, which is the first thing that searches them.
 */
enum SearchIndex: string
{
    case Customers = 'customers';

    /** §7.1's suppliers, searched by the Module 4 Point 2.1 list endpoint. */
    case Suppliers = 'suppliers';

    /** §7.3's catalog, searched by the Module 4 Point 3.1 list endpoint. */
    case Catalog = 'catalog_items';

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

            // §7.1 identifies a supplier by name, and `contact_person` is the
            // only other free-text column on the table. Adding it would be the
            // same unrecorded product decision the note above describes, so the
            // floor is the same here: one column, one line to change.
            self::Suppliers => ['name'],

            // Two columns, and the second one is documented rather than
            // guessed: §7.3 annotates the column "Category (for search)",
            // which is the enumeration the note above says no source gives
            // for the other tables. `product_code`, `company` and the two
            // descriptions have no such annotation and stay out until one
            // exists.
            self::Catalog => ['name', 'category'],
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

            // **Empty, and measured against the rule above rather than left
            // blank.** What belongs here is a filter that is *always* applied,
            // because the cap makes narrowing-after-search return the wrong
            // page rather than fewer rows. Customers has two such filters:
            // §3.3 scopes every read by owner and Flow 7 hides the archived.
            // §3.7 gives suppliers no scope at all, and §10.4's hiding rule
            // governs a selection list in Modules 6/7, not this screen — so no
            // supplier filter is always on, and none has to go inside.
            self::Suppliers => [],

            // **`kind`, and only `kind`.** §7.3 publishes "Two tabs: Product ·
            // Service" and the build plan asks that a service appear
            // "separate from products" — so the tab is not one filter among
            // several, it is which screen the person is looking at. A capped
            // search filled up by products and *then* narrowed to services
            // would show the wrong rows rather than fewer of the right ones,
            // which is exactly the failure this list exists to prevent.
            // `category` and `is_active` are optional refinements on one
            // screen and stay outside.
            self::Catalog => ['kind'],
        };
    }
}
