<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * One row of one D-71 pivot: which parent a file hangs from.
 *
 * D-38 makes the parent the source of permission, so this pair is the whole
 * question an authorisation check has to answer. A file may have several.
 */
final readonly class AttachmentLink
{
    public function __construct(
        public AttachmentParent $parent,
        public string $parentId,
    ) {}
}
