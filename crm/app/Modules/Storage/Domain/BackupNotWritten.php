<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

use RuntimeException;

/** Module 16 · 5: the writer could not finish; nothing it started is left behind. */
final class BackupNotWritten extends RuntimeException {}
