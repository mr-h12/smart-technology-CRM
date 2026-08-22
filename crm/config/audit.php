<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Audit log partitions (D-72, DB-10, J-15)
    |---------------------------------------------------------------------------
    |
    | How many months ahead J-15 guarantees a partition for, counting the
    | current one. Three is a margin, not a measurement: OD-05 — expected daily
    | workload — is still open, so nothing here is sized against real volume.
    | What it buys is tolerance for the job not running. At 3, the scheduler can
    | be down for two whole months before a row reaches the default partition;
    | at 1 it can be down for a few weeks; at 0 the table dies at the next
    | month boundary.
    |
    | Costs nothing to raise: an empty partition is an empty file plus five
    | index entries in the catalogue.
    |
    */

    'partitions' => [
        'months_ahead' => (int) env('AUDIT_PARTITION_MONTHS_AHEAD', 3),
    ],

];
