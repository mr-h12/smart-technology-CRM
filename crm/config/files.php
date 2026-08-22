<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum upload size
    |--------------------------------------------------------------------------
    |
    | §17 and D-71: 30 MB, superseding the 10 MB in D-39. Configurable, which is
    | why it is here and not a constant — CLAUDE.md keeps limits out of code.
    |
    | This is an interim home. §17 calls the limit configurable and D-14 puts
    | configuration in the database; the settings table is Module 2, and this
    | value moves there when it exists. Until then a deployment can still change
    | it without a code change, through the environment.
    |
    | It is not the only ceiling. PHP's upload_max_filesize and post_max_size and
    | nginx's client_max_body_size all cut before it, and raising this number
    | past them produces a rejection with no message that explains itself — the
    | request simply arrives with no file in it. docker/php/Dockerfile and
    | docker/nginx/default.conf are set above this value, and FilesMigrationTest
    | asserts the relationship rather than trusting the comment.
    |
    */

    'max_size_bytes' => (int) env('FILES_MAX_SIZE_BYTES', 30 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Virus scanning
    |--------------------------------------------------------------------------
    |
    | SEC-15 and §17 make scanning mandatory on every upload, and the download
    | endpoint serves nothing whose status is not `clean`.
    |
    | 'clamav' is the only real scanner. 'eicar' knows exactly one signature —
    | the EICAR test file — and exists so CI and a developer machine can prove
    | the wiring without running a daemon. It is **not** protection, and a test
    | asserts that it calls everything else clean so the fact cannot be lost.
    |
    | The daemon itself is not in this stack yet; it is on the deployment-debt
    | register in CHECKLIST.md.
    |
    */

    'scanner' => env('FILES_VIRUS_SCANNER', 'eicar'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        // Seconds. A scan that hangs must fail, not wait: an unanswered scanner
        // leaves the file `pending`, which keeps it undownloadable.
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

];
