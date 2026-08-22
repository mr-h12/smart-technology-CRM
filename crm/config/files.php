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

];
