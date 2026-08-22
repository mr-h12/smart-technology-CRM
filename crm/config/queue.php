<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            // NOT Laravel's 'default'. §15.1 has four queues and four worker
            // services drain exactly those; a queue named `default` has a
            // producer and no consumer, so a job dispatched without ->onQueue()
            // would be accepted, stored, and never run — silent on both sides.
            //
            // `maintenance` rather than `critical` because the choice is an
            // interpretation, not a documented value, and this is the direction
            // whose failure is survivable: an unclassified job runs late.
            // Defaulting to `critical` would let forgotten annotations pile up
            // on the queue whose whole purpose is recovery and system health.
            'queue' => env('REDIS_QUEUE', 'maintenance'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | The Four Queues (§15.1)
    |--------------------------------------------------------------------------
    |
    | Not a Laravel key — this project's. §15.1 names four queues and gives
    | them a priority order, and docker-compose.yml runs one worker service per
    | queue so that priority is backed by separated capacity rather than by
    | intention. The order below IS that priority: 1 critical, 2 pdf,
    | 3 reports, 4 maintenance.
    |
    | Here rather than in a class because AP-08 is configuration over code.
    | App\Support\Queue\QueueName is the typed way to name one at a call site;
    | QueueConfigurationTest asserts the two agree and that a worker in
    | docker-compose.yml drains each one, because nothing else connects a PHP
    | string to a `--queue=` flag in another file.
    |
    */

    'crm' => [
        'queues' => [
            'critical',      // 1 — recovery · system health
            'pdf',           // 2 — PDF generation (PRF-04)
            'reports',       // 3 — report generation
            'maintenance',   // 4 — cleanup · indexing · aggregates
        ],
    ],

];
