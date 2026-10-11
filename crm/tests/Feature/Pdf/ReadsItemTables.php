<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

/**
 * The customer PDF's item tables, read from its markup — F-40 · 1.6b/1.6c put
 * each page's rows in a table of its own, and both the template's tests and
 * the renderer's ask which rows landed in which.
 */
trait ReadsItemTables
{
    /** @return list<string> each item table's markup, in page order */
    private static function itemTables(string $html): array
    {
        preg_match_all('/<table class="items[^"]*">.*?<\/table>/s', $html, $matches);

        return $matches[0];
    }

    /** @return list<string> */
    private static function descriptions(string $table): array
    {
        preg_match_all('/<td class="desc">\s*(.*?)\s*<\/td>/s', $table, $matches);

        return $matches[1];
    }
}
