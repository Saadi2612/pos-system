<?php

if (!function_exists('settings')) {
    function settings() {
        $settings = cache()->remember('settings', 24*60, function () {
            return \Modules\Setting\Entities\Setting::firstOrFail();
        });

        return $settings;
    }
}

if (!function_exists('format_currency')) {
    function format_currency($value, $format = true) {
        if (!$format) {
            return $value;
        }

        $settings = settings();
        $position = $settings->default_currency_position;
        $symbol = $settings->currency->symbol;
        $decimal_separator = $settings->currency->decimal_separator;
        $thousand_separator = $settings->currency->thousand_separator;

        if ($position == 'prefix') {
            $formatted_value = $symbol . number_format((float) $value, 2, $decimal_separator, $thousand_separator);
        } else {
            $formatted_value = number_format((float) $value, 2, $decimal_separator, $thousand_separator) . $symbol;
        }

        return $formatted_value;
    }
}

if (!function_exists('make_reference_id')) {
    function make_reference_id($prefix, $number) {
        $padded_text = $prefix . '-' . str_pad($number, 5, 0, STR_PAD_LEFT);

        return $padded_text;
    }
}

if (!function_exists('array_merge_numeric_values')) {
    function array_merge_numeric_values() {
        $arrays = func_get_args();
        $merged = array();
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key] += $value;
                }
            }
        }

        return $merged;
    }
}

if (!function_exists('money_to_minor')) {
    /**
     * Convert a decimal amount (e.g. "19.99", 19.99) to integer minor units (1999).
     * Uses string/rounding arithmetic so float artefacts (19.99 * 100 = 1998.9999...) never leak.
     */
    function money_to_minor($value): int {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round(((float) str_replace(',', '', (string) $value)) * 100);
    }
}

if (!function_exists('minor_to_money')) {
    /** Convert integer minor units to a decimal amount. */
    function minor_to_money($value): float {
        return ((int) $value) / 100;
    }
}

if (!function_exists('format_minor')) {
    /** Format integer minor units with the configured currency. */
    function format_minor($value, $format = true) {
        return format_currency(((int) $value) / 100, $format);
    }
}

if (!function_exists('balance_label')) {
    /** "1,000.00 Dr" style label: customer positive = Dr (owes us); supplier positive = Cr (we owe). */
    function balance_label(string $partyType, int $balance): string {
        if ($balance === 0) {
            return format_minor(0);
        }

        $debit = $partyType === 'customer' ? $balance > 0 : $balance < 0;

        return format_minor(abs($balance)) . ($debit ? ' Dr' : ' Cr');
    }
}
