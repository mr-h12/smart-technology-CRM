<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // §17: no direct access. `serve => true` registers GET and PUT on
            // /storage/{path}; nothing in this system wants a URL that returns a
            // file without asking who is asking. Until Point 5.4 the route was
            // unreachable only because the SPA catch-all shadowed it, which is
            // an accident rather than a control.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | §17 attachments. Private by construction, not by convention.
        |
        | `serve` is false and there is no `url`: Laravel 11 will publish a
        | local disk over /storage when asked, and §17 requires every file to
        | pass a permission check on its parent entity (D-38) before a byte is
        | returned. A disk that can be linked to has already lost that argument.
        |
        | `throw` is true because the alternative is a write that returns false
        | and a request that reports success — §17 puts files in the backup set,
        | which assumes they were written in the first place.
        |
        | The root is STORAGE_PATH, which already exists: docker-compose.yml
        | mounts the named volume `crm-storage` there for php and every worker,
        | the Dockerfile creates it 0750 www-data, and nginx deliberately does
        | not mount it at all. §17 asks for a path *outside the application
        | directory* and that volume is the answer — storage/ would not have
        | been, since it sits inside the bind mount. Reading it from the
        | environment also keeps AP-08's config-over-code: moving the root is a
        | deployment change, not an edit to a class.
        */
        'secure_uploads' => [
            'driver' => 'local',
            'root' => env('STORAGE_PATH', '/var/crm-files'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
