<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * Why an upload was refused — and the key that explains it to a person.
 *
 * A key rather than a sentence: CLAUDE.md keeps every user-facing string in a
 * lang file, and the domain has no translator to call. The HTTP layer turns
 * this into Arabic or English at the boundary (Point 5.4).
 */
enum UploadRejectionReason: string
{
    case Unreadable = 'unreadable';
    case EmptyFile = 'empty';
    case TooLarge = 'too_large';
    case UnsupportedType = 'unsupported_type';
    case Corrupted = 'corrupted';

    public function translationKey(): string
    {
        return 'uploads.rejected.'.$this->value;
    }
}
