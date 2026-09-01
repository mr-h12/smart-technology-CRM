<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\ValueObjects;

use App\Modules\Storage\Domain\AttachmentParent;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The §17 path: `/{year}/{month}/{entity_type}/{entity_id}/{uuid}.ext`.
 *
 * A value object rather than a string built at the call site, because every
 * segment here is an invariant somebody could break by hand. The identifiers
 * are checked against a UUID shape and the extension against a short alphabet:
 * a path is assembled from user-supplied material, and `..` in any segment
 * walks out of the storage root. Nothing in this class knows what a disk is —
 * it produces a relative path and stops, which is what lets the domain hold it.
 */
final readonly class StoragePath
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const EXTENSION = '/^[a-z0-9]{2,8}$/';

    private const STORED = '#^\\d{4}/\\d{2}/[a-z_]{1,32}/[0-9a-f-]{36}/[0-9a-f-]{36}\\.[a-z0-9]{2,8}$#';

    private function __construct(public string $value) {}

    /**
     * @param  string  $parentId  the parent row's UUID primary key (D-61)
     * @param  string  $uuid  the stored file's name — §17 keeps the original in the database
     * @param  string  $extension  already separated from the original name by the caller
     * @param  DateTimeImmutable  $storedAt  converted to UTC here, because DB-08 stores UTC
     */
    public static function for(
        AttachmentParent $parent,
        string $parentId,
        string $uuid,
        string $extension,
        DateTimeImmutable $storedAt,
    ): self {
        self::assertUuid($parentId, 'parent id');
        self::assertUuid($uuid, 'file name');

        $extension = strtolower($extension);

        if (preg_match(self::EXTENSION, $extension) !== 1) {
            throw new InvalidArgumentException(
                "A stored file needs a plain extension; got '{$extension}'."
            );
        }

        // In UTC, not in the reader's timezone. A path taken from local time
        // files one object under two different months depending on who uploaded
        // it, and a folder that moves is a folder a backup cannot follow.
        $utc = $storedAt->setTimezone(new DateTimeZone('UTC'));

        return new self(sprintf(
            '%s/%s/%s/%s/%s.%s',
            $utc->format('Y'),
            $utc->format('m'),
            $parent->value,
            $parentId,
            $uuid,
            $extension,
        ));
    }

    /**
     * Rebuilds a path that was written to `files.storage_path`.
     *
     * Re-checked against the same shape rather than trusted, because a row is
     * not a safer source than a request: anything that could corrupt the column
     * — a bad migration, a restored backup, an injection somewhere upstream —
     * would otherwise reach the filesystem through here.
     */
    public static function fromStored(string $value): self
    {
        if (preg_match(self::STORED, $value) !== 1) {
            throw new InvalidArgumentException('The stored path does not have the §17 shape.');
        }

        return new self($value);
    }

    /**
     * The stored file's own id — `files.id`, per the migration's own comment:
     * "`{uuid}` is `files.id`, so no second identifier column exists to
     * drift." `store()` mints this uuid and folds it straight into the path
     * without handing it back separately, so a writer that must persist the
     * row this path belongs to reads it from here rather than generating a
     * second one that would drift from the name already on disk.
     */
    public function fileId(): string
    {
        $segments = explode('/', $this->value);

        /** @var string $filename */
        $filename = end($segments);

        return pathinfo($filename, PATHINFO_FILENAME);
    }

    private static function assertUuid(string $candidate, string $label): void
    {
        if (preg_match(self::UUID, $candidate) !== 1) {
            throw new InvalidArgumentException(
                "A storage path segment must be a UUID; the {$label} was not one."
            );
        }
    }
}
