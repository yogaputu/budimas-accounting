<?php

namespace Tests\Unit;

use App\Accounting\AccountingAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingAmountTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_formats_money_without_losing_decimal_precision(string $amount, string $expected): void
    {
        $this->assertSame($expected, AccountingAmount::format($amount));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => ['0', '0,00'],
            'fractional cent scale' => ['0.10', '0,10'],
            'negative balance' => ['-1234567.89', '-1.234.567,89'],
            'full database precision' => ['999999999999999999.99', '999.999.999.999.999.999,99'],
            'negative full precision' => ['-999999999999999999.99', '-999.999.999.999.999.999,99'],
        ];
    }

    public function test_sums_decimal_amounts_without_losing_cents(): void
    {
        $this->assertSame('0.01', AccountingAmount::sum(['999999999999999999.99', '-999999999999999999.98']));
        $this->assertSame('0.30', AccountingAmount::sum(['0.10', '0.20']));
        $this->assertSame('0.00', AccountingAmount::sum([]));
    }
}
