<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_that_true_is_true(): void
    {
        // The skeleton asserted true is true, which static analysis correctly
        // reports as always passing.
        //
        // A unit test must not need the framework — tests/Unit does not boot the
        // application, which is the whole point of the split in Coding Standards
        // §13.1. Asserting on app() here failed for exactly that reason. This
        // asserts something real and framework-free instead: BCMath is present
        // and exact, which D-57 and DB-07 make non-negotiable for money.
        $this->assertSame('0.3', bcadd('0.1', '0.2', 1));
        $this->assertNotSame(0.3, 0.1 + 0.2);
    }
}
