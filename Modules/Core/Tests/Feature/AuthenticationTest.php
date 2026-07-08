<?php

namespace Modules\Core\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    /** @test */
    public function it_can_assert_true()
    {
        $this->assertTrue(true);
    }

    public function testDummy(): void { $this->assertTrue(true); }
}