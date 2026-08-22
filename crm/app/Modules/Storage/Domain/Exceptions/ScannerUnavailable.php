<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Exceptions;

use RuntimeException;

/**
 * The scanner could not give an answer.
 *
 * A separate exception rather than a fourth ScanStatus, because "I could not
 * check" is not a property of the file and must never be written into its row.
 * An antivirus that is down and reports `clean` is worse than no antivirus at
 * all: the column then says the file was checked, and every later reader
 * believes it.
 */
final class ScannerUnavailable extends RuntimeException {}
