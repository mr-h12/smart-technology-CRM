<?php

declare(strict_types=1);

namespace App\Modules\Storage\Presentation;

use App\Modules\Storage\Application\DownloadFile;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/v1/files/{file}/download` — §17's permission-checking endpoint.
 *
 * Thin on purpose (Coding Standards): resolve the actor, invoke the use case,
 * stream. It holds no permission rule, because it is not allowed to know one.
 */
final class DownloadFileController
{
    /**
     * Dependencies arrive per call, not through the constructor.
     *
     * `Route::getController()` memoises the controller on the Route object, and
     * a Route lives for the life of the process. Constructor-injected services
     * are therefore built once and reused by every later request in that
     * process — measured here: rebinding the permission after the first request
     * changed nothing, allow → 200, deny → **200**, allow → 200. Under PHP-FPM
     * each request is a fresh process so this hides; under a long-lived worker,
     * or in a test, an authorisation policy would be frozen at whatever the
     * first request happened to resolve. Method arguments are resolved from the
     * container on every call, which is what an authorisation-bearing endpoint
     * needs.
     */
    public function __invoke(
        Request $request,
        string $file,
        DownloadFile $download,
        StorageServiceInterface $storage,
    ): StreamedResponse {
        $actor = $request->user();

        // The route carries the `auth` middleware, so this is belt and braces
        // rather than the check itself — but an endpoint that streams private
        // files should not depend on middleware configuration staying correct.
        abort_if($actor === null, 401);

        $actorId = $actor->getAuthIdentifier();

        // An identifier that is neither a string nor an int is not an actor this
        // endpoint can reason about, and guessing would mean asking the policy
        // about "Array". D-61 makes it a UUID string.
        abort_if(! is_string($actorId) && ! is_int($actorId), 401);

        $stored = $download->forActor($file, (string) $actorId);

        // One status for "no such file" and for "not yours" — OpenAPI: do not
        // reveal which case applies. The use case has already thrown the
        // distinction away, so there is nothing here to leak.
        abort_if($stored === null, 404);

        $stream = $storage->readStream($stored->path);

        return new StreamedResponse(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $stored->mimeType,
                'Content-Length' => (string) $stored->sizeBytes,
                // The stored MIME is one of D-40's six, checked from the bytes
                // at upload (Point 5.3). nosniff stops a browser overriding it
                // anyway and executing what it decides the content really is.
                'X-Content-Type-Options' => 'nosniff',
                // makeDisposition emits filename* per RFC 5987, which is what
                // carries an Arabic name through a header that is ASCII only.
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $stored->originalName,
                    self::asciiFallback($stored->originalName),
                ),
                // Permission is re-checked per request (OpenAPI §8.3); a shared
                // cache holding the bytes would serve the next caller without it.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /** Something a header can always carry, when the real name is not ASCII. */
    private static function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name);

        return is_string($ascii) && trim($ascii, '_ ') !== '' ? $ascii : 'download';
    }
}
