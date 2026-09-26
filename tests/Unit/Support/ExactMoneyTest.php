<?php

namespace Tests\Unit\Support;

use App\Support\ExactMoney;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExactMoneyTest extends TestCase
{
    public function test_converts_egp_decimal_strings_to_minor_units_without_float_math(): void
    {
        $this->assertSame(1, ExactMoney::toMinorUnitInteger('0.01'));
        $this->assertSame(100, ExactMoney::toMinorUnitInteger('1.00'));
        $this->assertSame(1000, ExactMoney::toMinorUnitInteger('10'));
        $this->assertSame(45050, ExactMoney::toMinorUnitInteger('450.50'));
        $this->assertSame(99999999, ExactMoney::toMinorUnitInteger('999999.99'));
    }

    #[DataProvider('invalidMoneyValues')]
    public function test_rejects_negative_and_malformed_values(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactMoney::toMinorUnitInteger($value);
    }

    public static function invalidMoneyValues(): array
    {
        return [
            'negative' => ['-0.01'],
            'empty string' => [''],
            'alphabetic input' => ['one'],
            'too precise' => ['1.234'],
            'multiple decimal points' => ['1.2.3'],
            'missing fraction digits' => ['1.'],
            'missing whole digits' => ['.50'],
            'scientific notation' => ['1e2'],
            'explicit positive sign' => ['+1.00'],
            'thousands separator' => ['1,000.00'],
        ];
    }

    public function test_rejects_integer_overflow(): void
    {
        $this->expectException(OverflowException::class);

        ExactMoney::toMinorUnitInteger(str_repeat('9', strlen((string) PHP_INT_MAX) + 1));
    }
}
