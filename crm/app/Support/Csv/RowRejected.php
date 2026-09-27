<?php

declare(strict_types=1);

namespace App\Support\Csv;

use RuntimeException;

/**
 * F-20 · 1.1 (`D-94`) — why one imported row was not saved, thrown where the
 * row is read and caught by the import's loop, which names the row by its
 * spreadsheet number. The three imports share it; their `ImportSummary`
 * carries the result as plain arrays, because `Domain` may not name this.
 *
 * The code is stable English and the sentence follows the request's language
 * (OpenAPI §4). The column is the one the file names, untranslated, as the
 * import's other messages name `«name»`.
 */
final class RowRejected extends RuntimeException
{
    private function __construct(public readonly string $field, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function required(string $field): self
    {
        return new self($field, 'required', (string) __('validation.required', ['attribute' => $field]));
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self($field, 'too_long', (string) __('validation.max.string', ['attribute' => $field, 'max' => $max]));
    }

    /** A value outside the set its column accepts: a word, a type, a managed-list entry. */
    public static function notAllowed(string $field): self
    {
        return new self($field, 'not_allowed', (string) __('validation.in', ['attribute' => $field]));
    }

    public static function notFound(string $field): self
    {
        return new self($field, 'not_found', (string) __('validation.exists', ['attribute' => $field]));
    }

    /** The one reason no validation sentence covers; the caller names its own. */
    public static function ambiguous(string $field, string $messageKey): self
    {
        return new self($field, 'ambiguous', (string) __($messageKey));
    }

    /** @return array{row: int, field: string, code: string, message: string} */
    public function at(int $row): array
    {
        return ['row' => $row, 'field' => $this->field, 'code' => $this->reason, 'message' => $this->getMessage()];
    }
}
