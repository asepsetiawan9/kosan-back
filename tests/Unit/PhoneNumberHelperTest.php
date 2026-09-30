<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberHelperTest extends TestCase
{
    public function test_normalizes_standard_08_prefix(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('081234567890'));
    }

    public function test_normalizes_plus_62_prefix(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('+6281234567890'));
    }

    public function test_keeps_already_normalized_62_prefix(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('6281234567890'));
    }

    public function test_normalizes_dashes_and_spaces(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', PhoneNumber::normalize('  0812 3456 7890  '));
        $this->assertSame('6281234567890', PhoneNumber::normalize('+62 812-3456-7890'));
    }

    public function test_normalizes_starting_with_8(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('81234567890'));
    }

    public function test_validation_works_correctly(): void
    {
        $this->assertTrue(PhoneNumber::isValid('081234567890'));
        $this->assertTrue(PhoneNumber::isValid('+6281234567890'));
        $this->assertTrue(PhoneNumber::isValid('0812-3456-7890'));
        $this->assertFalse(PhoneNumber::isValid('12345'));
        $this->assertFalse(PhoneNumber::isValid('0215551234')); // Landline, not 08 mobile
        $this->assertFalse(PhoneNumber::isValid('abcdef'));
    }

    public function test_formats_display(): void
    {
        $this->assertSame('+62 812-3456-7890', PhoneNumber::formatDisplay('081234567890'));
    }
}
