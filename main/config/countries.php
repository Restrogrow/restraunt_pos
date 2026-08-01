<?php
/**
 * Shared country reference data: dial code, currency, typical local phone length.
 * Single source of truth - consumed server-side (signup/settings defaults, tax/phone
 * validation) and emitted as JSON to the client for country-aware dropdowns/auto-fill.
 *
 * phone_min/phone_max = typical length of the *local* subscriber number, excluding
 * the country dial code.
 */

function getCountryData() {
    static $countries = null;
    if ($countries !== null) return $countries;

    $countries = [
        'IN' => ['name' => 'India', 'dial_code' => '+91', 'currency_code' => 'INR', 'currency_symbol' => '₹', 'phone_min' => 10, 'phone_max' => 10],
        'NP' => ['name' => 'Nepal', 'dial_code' => '+977', 'currency_code' => 'NPR', 'currency_symbol' => 'Rs.', 'phone_min' => 10, 'phone_max' => 10],
        'US' => ['name' => 'United States', 'dial_code' => '+1', 'currency_code' => 'USD', 'currency_symbol' => '$', 'phone_min' => 10, 'phone_max' => 10],
        'CA' => ['name' => 'Canada', 'dial_code' => '+1', 'currency_code' => 'CAD', 'currency_symbol' => 'C$', 'phone_min' => 10, 'phone_max' => 10],
        'GB' => ['name' => 'United Kingdom', 'dial_code' => '+44', 'currency_code' => 'GBP', 'currency_symbol' => '£', 'phone_min' => 10, 'phone_max' => 10],
        'AU' => ['name' => 'Australia', 'dial_code' => '+61', 'currency_code' => 'AUD', 'currency_symbol' => 'A$', 'phone_min' => 9, 'phone_max' => 9],
        'NZ' => ['name' => 'New Zealand', 'dial_code' => '+64', 'currency_code' => 'NZD', 'currency_symbol' => 'NZ$', 'phone_min' => 8, 'phone_max' => 10],
        'AE' => ['name' => 'United Arab Emirates', 'dial_code' => '+971', 'currency_code' => 'AED', 'currency_symbol' => 'AED', 'phone_min' => 9, 'phone_max' => 9],
        'SA' => ['name' => 'Saudi Arabia', 'dial_code' => '+966', 'currency_code' => 'SAR', 'currency_symbol' => 'SAR', 'phone_min' => 9, 'phone_max' => 9],
        'QA' => ['name' => 'Qatar', 'dial_code' => '+974', 'currency_code' => 'QAR', 'currency_symbol' => 'QAR', 'phone_min' => 8, 'phone_max' => 8],
        'KW' => ['name' => 'Kuwait', 'dial_code' => '+965', 'currency_code' => 'KWD', 'currency_symbol' => 'KWD', 'phone_min' => 8, 'phone_max' => 8],
        'BH' => ['name' => 'Bahrain', 'dial_code' => '+973', 'currency_code' => 'BHD', 'currency_symbol' => 'BHD', 'phone_min' => 8, 'phone_max' => 8],
        'OM' => ['name' => 'Oman', 'dial_code' => '+968', 'currency_code' => 'OMR', 'currency_symbol' => 'OMR', 'phone_min' => 8, 'phone_max' => 8],
        'PK' => ['name' => 'Pakistan', 'dial_code' => '+92', 'currency_code' => 'PKR', 'currency_symbol' => 'Rs', 'phone_min' => 10, 'phone_max' => 10],
        'BD' => ['name' => 'Bangladesh', 'dial_code' => '+880', 'currency_code' => 'BDT', 'currency_symbol' => '৳', 'phone_min' => 10, 'phone_max' => 10],
        'LK' => ['name' => 'Sri Lanka', 'dial_code' => '+94', 'currency_code' => 'LKR', 'currency_symbol' => 'Rs', 'phone_min' => 9, 'phone_max' => 9],
        'BT' => ['name' => 'Bhutan', 'dial_code' => '+975', 'currency_code' => 'BTN', 'currency_symbol' => 'Nu.', 'phone_min' => 8, 'phone_max' => 8],
        'MM' => ['name' => 'Myanmar', 'dial_code' => '+95', 'currency_code' => 'MMK', 'currency_symbol' => 'K', 'phone_min' => 8, 'phone_max' => 10],
        'SG' => ['name' => 'Singapore', 'dial_code' => '+65', 'currency_code' => 'SGD', 'currency_symbol' => 'S$', 'phone_min' => 8, 'phone_max' => 8],
        'MY' => ['name' => 'Malaysia', 'dial_code' => '+60', 'currency_code' => 'MYR', 'currency_symbol' => 'RM', 'phone_min' => 9, 'phone_max' => 10],
        'TH' => ['name' => 'Thailand', 'dial_code' => '+66', 'currency_code' => 'THB', 'currency_symbol' => '฿', 'phone_min' => 9, 'phone_max' => 9],
        'ID' => ['name' => 'Indonesia', 'dial_code' => '+62', 'currency_code' => 'IDR', 'currency_symbol' => 'Rp', 'phone_min' => 9, 'phone_max' => 12],
        'PH' => ['name' => 'Philippines', 'dial_code' => '+63', 'currency_code' => 'PHP', 'currency_symbol' => '₱', 'phone_min' => 10, 'phone_max' => 10],
        'VN' => ['name' => 'Vietnam', 'dial_code' => '+84', 'currency_code' => 'VND', 'currency_symbol' => '₫', 'phone_min' => 9, 'phone_max' => 10],
        'KH' => ['name' => 'Cambodia', 'dial_code' => '+855', 'currency_code' => 'KHR', 'currency_symbol' => '៛', 'phone_min' => 8, 'phone_max' => 9],
        'LA' => ['name' => 'Laos', 'dial_code' => '+856', 'currency_code' => 'LAK', 'currency_symbol' => '₭', 'phone_min' => 8, 'phone_max' => 10],
        'CN' => ['name' => 'China', 'dial_code' => '+86', 'currency_code' => 'CNY', 'currency_symbol' => '¥', 'phone_min' => 11, 'phone_max' => 11],
        'HK' => ['name' => 'Hong Kong', 'dial_code' => '+852', 'currency_code' => 'HKD', 'currency_symbol' => 'HK$', 'phone_min' => 8, 'phone_max' => 8],
        'TW' => ['name' => 'Taiwan', 'dial_code' => '+886', 'currency_code' => 'TWD', 'currency_symbol' => 'NT$', 'phone_min' => 9, 'phone_max' => 9],
        'JP' => ['name' => 'Japan', 'dial_code' => '+81', 'currency_code' => 'JPY', 'currency_symbol' => '¥', 'phone_min' => 10, 'phone_max' => 10],
        'KR' => ['name' => 'South Korea', 'dial_code' => '+82', 'currency_code' => 'KRW', 'currency_symbol' => '₩', 'phone_min' => 9, 'phone_max' => 10],
        'AF' => ['name' => 'Afghanistan', 'dial_code' => '+93', 'currency_code' => 'AFN', 'currency_symbol' => 'Af', 'phone_min' => 9, 'phone_max' => 9],
        'IE' => ['name' => 'Ireland', 'dial_code' => '+353', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 9],
        'DE' => ['name' => 'Germany', 'dial_code' => '+49', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 10, 'phone_max' => 11],
        'FR' => ['name' => 'France', 'dial_code' => '+33', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 9],
        'IT' => ['name' => 'Italy', 'dial_code' => '+39', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 10],
        'ES' => ['name' => 'Spain', 'dial_code' => '+34', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 9],
        'PT' => ['name' => 'Portugal', 'dial_code' => '+351', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 9],
        'NL' => ['name' => 'Netherlands', 'dial_code' => '+31', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 9],
        'BE' => ['name' => 'Belgium', 'dial_code' => '+32', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 8, 'phone_max' => 9],
        'CH' => ['name' => 'Switzerland', 'dial_code' => '+41', 'currency_code' => 'CHF', 'currency_symbol' => 'CHF', 'phone_min' => 9, 'phone_max' => 9],
        'AT' => ['name' => 'Austria', 'dial_code' => '+43', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 10, 'phone_max' => 11],
        'SE' => ['name' => 'Sweden', 'dial_code' => '+46', 'currency_code' => 'SEK', 'currency_symbol' => 'kr', 'phone_min' => 9, 'phone_max' => 9],
        'NO' => ['name' => 'Norway', 'dial_code' => '+47', 'currency_code' => 'NOK', 'currency_symbol' => 'kr', 'phone_min' => 8, 'phone_max' => 8],
        'DK' => ['name' => 'Denmark', 'dial_code' => '+45', 'currency_code' => 'DKK', 'currency_symbol' => 'kr', 'phone_min' => 8, 'phone_max' => 8],
        'FI' => ['name' => 'Finland', 'dial_code' => '+358', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 9, 'phone_max' => 10],
        'PL' => ['name' => 'Poland', 'dial_code' => '+48', 'currency_code' => 'PLN', 'currency_symbol' => 'zł', 'phone_min' => 9, 'phone_max' => 9],
        'GR' => ['name' => 'Greece', 'dial_code' => '+30', 'currency_code' => 'EUR', 'currency_symbol' => '€', 'phone_min' => 10, 'phone_max' => 10],
        'RO' => ['name' => 'Romania', 'dial_code' => '+40', 'currency_code' => 'RON', 'currency_symbol' => 'lei', 'phone_min' => 9, 'phone_max' => 9],
        'CZ' => ['name' => 'Czech Republic', 'dial_code' => '+420', 'currency_code' => 'CZK', 'currency_symbol' => 'Kč', 'phone_min' => 9, 'phone_max' => 9],
        'HU' => ['name' => 'Hungary', 'dial_code' => '+36', 'currency_code' => 'HUF', 'currency_symbol' => 'Ft', 'phone_min' => 9, 'phone_max' => 9],
        'RU' => ['name' => 'Russia', 'dial_code' => '+7', 'currency_code' => 'RUB', 'currency_symbol' => '₽', 'phone_min' => 10, 'phone_max' => 10],
        'UA' => ['name' => 'Ukraine', 'dial_code' => '+380', 'currency_code' => 'UAH', 'currency_symbol' => '₴', 'phone_min' => 9, 'phone_max' => 9],
        'TR' => ['name' => 'Turkey', 'dial_code' => '+90', 'currency_code' => 'TRY', 'currency_symbol' => '₺', 'phone_min' => 10, 'phone_max' => 10],
        'IL' => ['name' => 'Israel', 'dial_code' => '+972', 'currency_code' => 'ILS', 'currency_symbol' => '₪', 'phone_min' => 9, 'phone_max' => 9],
        'EG' => ['name' => 'Egypt', 'dial_code' => '+20', 'currency_code' => 'EGP', 'currency_symbol' => 'E£', 'phone_min' => 10, 'phone_max' => 10],
        'ZA' => ['name' => 'South Africa', 'dial_code' => '+27', 'currency_code' => 'ZAR', 'currency_symbol' => 'R', 'phone_min' => 9, 'phone_max' => 9],
        'NG' => ['name' => 'Nigeria', 'dial_code' => '+234', 'currency_code' => 'NGN', 'currency_symbol' => '₦', 'phone_min' => 10, 'phone_max' => 10],
        'KE' => ['name' => 'Kenya', 'dial_code' => '+254', 'currency_code' => 'KES', 'currency_symbol' => 'KSh', 'phone_min' => 9, 'phone_max' => 9],
        'GH' => ['name' => 'Ghana', 'dial_code' => '+233', 'currency_code' => 'GHS', 'currency_symbol' => 'GH₵', 'phone_min' => 9, 'phone_max' => 9],
        'TZ' => ['name' => 'Tanzania', 'dial_code' => '+255', 'currency_code' => 'TZS', 'currency_symbol' => 'TSh', 'phone_min' => 9, 'phone_max' => 9],
        'ET' => ['name' => 'Ethiopia', 'dial_code' => '+251', 'currency_code' => 'ETB', 'currency_symbol' => 'Br', 'phone_min' => 9, 'phone_max' => 9],
        'MA' => ['name' => 'Morocco', 'dial_code' => '+212', 'currency_code' => 'MAD', 'currency_symbol' => 'MAD', 'phone_min' => 9, 'phone_max' => 9],
        'DZ' => ['name' => 'Algeria', 'dial_code' => '+213', 'currency_code' => 'DZD', 'currency_symbol' => 'DA', 'phone_min' => 9, 'phone_max' => 9],
        'TN' => ['name' => 'Tunisia', 'dial_code' => '+216', 'currency_code' => 'TND', 'currency_symbol' => 'DT', 'phone_min' => 8, 'phone_max' => 8],
        'JO' => ['name' => 'Jordan', 'dial_code' => '+962', 'currency_code' => 'JOD', 'currency_symbol' => 'JOD', 'phone_min' => 9, 'phone_max' => 9],
        'LB' => ['name' => 'Lebanon', 'dial_code' => '+961', 'currency_code' => 'LBP', 'currency_symbol' => 'LL', 'phone_min' => 7, 'phone_max' => 8],
        'IQ' => ['name' => 'Iraq', 'dial_code' => '+964', 'currency_code' => 'IQD', 'currency_symbol' => 'IQD', 'phone_min' => 10, 'phone_max' => 10],
        'IR' => ['name' => 'Iran', 'dial_code' => '+98', 'currency_code' => 'IRR', 'currency_symbol' => 'Rl', 'phone_min' => 10, 'phone_max' => 10],
        'BR' => ['name' => 'Brazil', 'dial_code' => '+55', 'currency_code' => 'BRL', 'currency_symbol' => 'R$', 'phone_min' => 10, 'phone_max' => 11],
        'MX' => ['name' => 'Mexico', 'dial_code' => '+52', 'currency_code' => 'MXN', 'currency_symbol' => 'Mex$', 'phone_min' => 10, 'phone_max' => 10],
        'AR' => ['name' => 'Argentina', 'dial_code' => '+54', 'currency_code' => 'ARS', 'currency_symbol' => 'AR$', 'phone_min' => 10, 'phone_max' => 11],
        'CL' => ['name' => 'Chile', 'dial_code' => '+56', 'currency_code' => 'CLP', 'currency_symbol' => 'CL$', 'phone_min' => 9, 'phone_max' => 9],
        'CO' => ['name' => 'Colombia', 'dial_code' => '+57', 'currency_code' => 'COP', 'currency_symbol' => 'CO$', 'phone_min' => 10, 'phone_max' => 10],
        'PE' => ['name' => 'Peru', 'dial_code' => '+51', 'currency_code' => 'PEN', 'currency_symbol' => 'S/', 'phone_min' => 9, 'phone_max' => 9],
        'VE' => ['name' => 'Venezuela', 'dial_code' => '+58', 'currency_code' => 'VES', 'currency_symbol' => 'Bs.', 'phone_min' => 10, 'phone_max' => 10],
        'EC' => ['name' => 'Ecuador', 'dial_code' => '+593', 'currency_code' => 'USD', 'currency_symbol' => '$', 'phone_min' => 9, 'phone_max' => 9],
        'UY' => ['name' => 'Uruguay', 'dial_code' => '+598', 'currency_code' => 'UYU', 'currency_symbol' => 'UY$', 'phone_min' => 8, 'phone_max' => 8],
        'PY' => ['name' => 'Paraguay', 'dial_code' => '+595', 'currency_code' => 'PYG', 'currency_symbol' => '₲', 'phone_min' => 9, 'phone_max' => 9],
        'BO' => ['name' => 'Bolivia', 'dial_code' => '+591', 'currency_code' => 'BOB', 'currency_symbol' => 'Bs', 'phone_min' => 8, 'phone_max' => 8],
        'PA' => ['name' => 'Panama', 'dial_code' => '+507', 'currency_code' => 'PAB', 'currency_symbol' => 'B/.', 'phone_min' => 8, 'phone_max' => 8],
        'CR' => ['name' => 'Costa Rica', 'dial_code' => '+506', 'currency_code' => 'CRC', 'currency_symbol' => '₡', 'phone_min' => 8, 'phone_max' => 8],
        'GT' => ['name' => 'Guatemala', 'dial_code' => '+502', 'currency_code' => 'GTQ', 'currency_symbol' => 'Q', 'phone_min' => 8, 'phone_max' => 8],
        'DO' => ['name' => 'Dominican Republic', 'dial_code' => '+1', 'currency_code' => 'DOP', 'currency_symbol' => 'RD$', 'phone_min' => 10, 'phone_max' => 10],
        'JM' => ['name' => 'Jamaica', 'dial_code' => '+1', 'currency_code' => 'JMD', 'currency_symbol' => 'J$', 'phone_min' => 10, 'phone_max' => 10],
        'TT' => ['name' => 'Trinidad and Tobago', 'dial_code' => '+1', 'currency_code' => 'TTD', 'currency_symbol' => 'TT$', 'phone_min' => 10, 'phone_max' => 10],
        'FJ' => ['name' => 'Fiji', 'dial_code' => '+679', 'currency_code' => 'FJD', 'currency_symbol' => 'FJ$', 'phone_min' => 7, 'phone_max' => 7],
        'MU' => ['name' => 'Mauritius', 'dial_code' => '+230', 'currency_code' => 'MUR', 'currency_symbol' => 'Rs', 'phone_min' => 7, 'phone_max' => 8],
        'MV' => ['name' => 'Maldives', 'dial_code' => '+960', 'currency_code' => 'MVR', 'currency_symbol' => 'Rf', 'phone_min' => 7, 'phone_max' => 7],
    ];

    ksort($countries);
    return $countries;
}

function getCountryByName($name) {
    foreach (getCountryData() as $iso2 => $c) {
        if (strcasecmp($c['name'], $name) === 0) return array_merge(['iso2' => $iso2], $c);
    }
    return null;
}
