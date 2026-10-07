<?php

namespace App\Accounting;

final class AccountingAmount
{
    /**
     * @param  iterable<int, string|int|float>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    public static function format(string $amount): string
    {
        [$whole, $fraction] = explode('.', bcadd($amount, '0', 2));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$fraction;
    }
}
