<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\View;

use InvalidArgumentException;

/**
 * One term under *Terms and Conditions*, as the PDF prints it — `D-103`.
 *
 * `key` marks one of the three ready terms (`payment_terms`, `warranty`,
 * `delivery_terms`) or is null for a term the employee added. `title` is the
 * name the employee typed, or null when they typed none: the template then
 * prints the PDF's own label for the key, in the PDF's language (ruling 4),
 * because a quotation has no language of its own.
 *
 * Restated here rather than reusing Quotations' array shape for the reason
 * {@see CustomerAdditionalLine} gives: the customer view is one vocabulary.
 */
final readonly class CustomerTerm
{
    public function __construct(
        public ?string $key,
        public ?string $title,
        public string $body,
    ) {
        // Ruling 3: a term with no body does not print, so it never gets here.
        if (trim($body) === '') {
            throw new InvalidArgumentException('A term\'s body must not be blank: a term with no body is left out of the PDF.');
        }

        // An empty name arrives as null, and only a ready term has a label to
        // fall back on — otherwise the line would print ": body".
        if ($title !== null ? trim($title) === '' : $key === null) {
            throw new InvalidArgumentException('A term\'s title must be absent (null) rather than blank, and a term with no key needs one.');
        }
    }
}
