<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

use RuntimeException;

/** The alert is not the caller's, or does not exist — the two answer alike (`SEC-09`). */
final class UnknownAlert extends RuntimeException {}
