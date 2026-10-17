<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * A stored timestamp written to the wire as UTC `DATE_ATOM` (`DB-08`), written
 * once (7.1) for the Reports module's four inline copies.
 */
final class UtcAtom
{
    public static function of(string $stored): string
    {
        return (new \DateTimeImmutable($stored))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
