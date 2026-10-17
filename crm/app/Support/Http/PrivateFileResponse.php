<?php

declare(strict_types=1);

namespace App\Support\Http;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A permission-checked file, streamed as an attachment — the one response both
 * `GET /files/{file}/download` and a report's PDF download return.
 */
final class PrivateFileResponse
{
    /**
     * @param  resource  $stream  closed once sent
     * @param  string  $asciiFallback  what the header carries when `$name` is not ASCII
     */
    public static function make($stream, string $mimeType, int $sizeBytes, string $name, string $asciiFallback): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $sizeBytes,
                // The stored MIME is one of D-40's six, checked from the bytes at upload.
                // nosniff stops a browser overriding it and executing what it decides the content is.
                'X-Content-Type-Options' => 'nosniff',
                // makeDisposition emits filename* per RFC 5987, which carries an Arabic name
                // through a header that is ASCII only.
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $asciiFallback),
                // Permission is re-checked per request (OpenAPI §8.3); a shared cache holding the
                // bytes would serve the next caller without it.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
