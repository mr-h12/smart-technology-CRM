<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AutofixCanaryTest extends TestCase
{
    public function test_the_canary_file_holds_exactly_the_marker(): void
    {
        $path = public_path('autofix-canary.txt');

        $this->assertFileExists($path);
        $this->assertSame('>>>v1<<<', file_get_contents($path));
    }
}
