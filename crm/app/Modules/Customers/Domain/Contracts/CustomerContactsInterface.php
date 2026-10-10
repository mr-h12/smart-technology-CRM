<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

/**
 * F-40 · 1.4 — the *Att.* line of `D-107`'s Commercial Offer: a customer's
 * single contact person (`D-18`) with `D-104`'s English title label. On the
 * terms of {@see CustomerNamesInterface}: no row scope, and neither archive
 * nor `deleted_at` changes the answer. The Arabic page prints `D-104`'s fixed
 * «أ.» for either title, so only the English label crosses.
 */
interface CustomerContactsInterface
{
    /**
     * `null` when the customer is unknown or has no contact (`D-89`: omitted).
     *
     * @return array{name: string, title_en: string|null}|null
     */
    public function contactOf(string $customerId): ?array;
}
