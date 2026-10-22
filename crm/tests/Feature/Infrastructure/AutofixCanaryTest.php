<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class AutofixCanaryTest extends TestCase
{
    public function test_canary_file_holds_exactly_v1(): void
    {
        $path = public_path('autofix-canary.txt');

        $this->assertFileExists($path);
        $this->assertSame('v1', file_get_contents($path));
    }
}
