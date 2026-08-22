<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AttachmentLink;

/**
 * D-38: "Attachment permission = permission on the parent entity".
 *
 * The seam, and deliberately nothing more. Storage cannot answer this question
 * and must not try: the permission matrix is database-backed dynamic RBAC
 * (SEC-07, Module 1) and the parents are Modules 5, 6, 10 and 13. Writing any
 * rule here would put a second, hard-coded authorisation model in Module 0,
 * which is exactly what CLAUDE.md forbids.
 *
 * Until those modules exist, DenyAllAttachmentPermission is bound. A default
 * that denies is a feature that does not work yet; a default that grants is a
 * hole nobody notices.
 */
interface AttachmentPermissionInterface
{
    public function mayView(AttachmentLink $link, string $actorId): bool;
}
