<?php

declare(strict_types=1);

/*
| Module 16 · 5 (`D-153`) — `J-10`, the minimal daily backup (`BK-01`, `BK-04`).
|
| `path` is the `crm-backups` volume (docker-compose.yml); a deployment change, not
| an edit to a class (AP-08). `pg_dump` is the PostgreSQL 17 client the PHP image
| installs; named here so a test can point it somewhere that does not exist.
*/
return [
    'path' => env('BACKUP_PATH', '/var/crm-backups'),
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
];
