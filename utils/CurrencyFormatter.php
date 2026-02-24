<?php

class CurrencyFormatter
{
    public static function formatINR(float $amount, int $decimals = 2, bool $withSymbol = true): string
    {
        $decimals = max(0, min(4, $decimals));
        $isNegative = $amount < 0;
        $absoluteAmount = abs($amount);

        $normalized = number_format($absoluteAmount, $decimals, '.', '');
        $parts = explode('.', $normalized, 2);
        $integerPart = $parts[0] ?? '0';
        $fractionPart = $parts[1] ?? '';

        if (strlen($integerPart) > 3) {
            $lastThree = substr($integerPart, -3);
            $leading = substr($integerPart, 0, -3);
            $leading = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $leading);
            $integerPart = $leading . ',' . $lastThree;
        }

        $result = $integerPart;
        if ($decimals > 0) {
            $result .= '.' . str_pad($fractionPart, $decimals, '0');
        }

        if ($withSymbol) {
            $result = '₹' . $result;
        }

        if ($isNegative) {
            $result = '-' . $result;
        }

        return $result;
    }

    public static function formatByCurrency(float $amount, string $currency = 'INR', int $decimals = 2): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'INR') {
            return self::formatINR($amount, $decimals, true);
        }

        return number_format($amount, $decimals) . ' ' . $currency;
    }
}

if (!function_exists('format_inr')) {
    function format_inr(float $amount, int $decimals = 2, bool $withSymbol = true): string
    {
        return CurrencyFormatter::formatINR($amount, $decimals, $withSymbol);
    }
}

if (!function_exists('format_currency_amount')) {
    function format_currency_amount(float $amount, string $currency = 'INR', int $decimals = 2): string
    {
        return CurrencyFormatter::formatByCurrency($amount, $currency, $decimals);
    }
}
