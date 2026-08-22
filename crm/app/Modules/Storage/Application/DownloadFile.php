<?php

declare(strict_types=1);

namespace App\Modules\Storage\Application;

use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\StoredFile;

/**
 * Decides whether an actor may have a file, and returns it if so.
 *
 * Every refusal returns null rather than a reason. The OpenAPI contract says a
 * 404 covers "does not exist **or** is not visible to the caller. Do not reveal
 * which case applies", and the cheapest way to honour that is to make the
 * distinction unavailable to the caller in the first place — including to the
 * controller, which therefore cannot leak it by accident.
 */
final readonly class DownloadFile
{
    public function __construct(
        private FileRepositoryInterface $files,
        private AttachmentPermissionInterface $permission,
    ) {}

    public function forActor(string $fileId, string $actorId): ?StoredFile
    {
        $file = $this->files->find($fileId);

        if ($file === null) {
            return null;
        }

        // SEC-15: scanning is mandatory on every upload, so anything that has
        // not come back clean is not servable — not to its owner either.
        if (! $file->isScannedClean()) {
            return null;
        }

        // D-38: permission is the parent's. No parent, nothing to inherit.
        foreach ($this->files->parentsOf($fileId) as $link) {
            if ($this->permission->mayView($link, $actorId)) {
                return $file;
            }
        }

        return null;
    }
}
