<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

use App\Modules\Storage\Domain\AllowedFileType;

/**
 * §17: "True MIME type, not the extension — a spoofed `.pdf` is rejected".
 *
 * The method takes a path and no name, on purpose. A browser controls both the
 * filename and the Content-Type header it sends; the only thing it cannot forge
 * is what the bytes are. Passing the name in would create somewhere for it to
 * be trusted, so it is not passed in — the returned type carries the extension
 * the file will be stored under.
 *
 * Names no framework type: Domain has an empty ruleset in deptrac.layers.yaml.
 *
 * @throws \App\Modules\Storage\Domain\Exceptions\UploadRejected
 */
interface UploadValidatorInterface
{
    public function validate(string $sourcePath): AllowedFileType;
}
