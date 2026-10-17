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
        if (strcasecmp($c['name'], (string)$name) === 0) return array_merge(['iso2' => $iso2], $c);
    }
    return null;
}

/**
 * The basic local setup a new restaurant starts with: its main time zone and the usual name and
 * rate of the sales tax on restaurant food. Only India's tax starts switched on; elsewhere the name
 * and rate are filled in but left off, because whether a restaurant must charge it (and whether menu
 * prices must already include it, as in Nepal) depends on its registration. Owners can change all of it
 * in Settings. [timezone, tax name, tax %]
 */
function getCountryLocalDefaults($iso2) {
    static $d = [
        'IN' => ['Asia/Kolkata', 'GST', 5], 'NP' => ['Asia/Kathmandu', 'VAT', 13], 'US' => ['America/New_York', 'Sales Tax', 0],
        'CA' => ['America/Toronto', 'GST', 5], 'GB' => ['Europe/London', 'VAT', 20], 'AU' => ['Australia/Sydney', 'GST', 10],
        'NZ' => ['Pacific/Auckland', 'GST', 15], 'AE' => ['Asia/Dubai', 'VAT', 5], 'SA' => ['Asia/Riyadh', 'VAT', 15],
        'QA' => ['Asia/Qatar', 'Tax', 0], 'KW' => ['Asia/Kuwait', 'Tax', 0], 'BH' => ['Asia/Bahrain', 'VAT', 10],
        'OM' => ['Asia/Muscat', 'VAT', 5], 'PK' => ['Asia/Karachi', 'Sales Tax', 16], 'BD' => ['Asia/Dhaka', 'VAT', 15],
        'LK' => ['Asia/Colombo', 'VAT', 18], 'BT' => ['Asia/Thimphu', 'Tax', 0], 'MM' => ['Asia/Yangon', 'Commercial Tax', 5],
        'SG' => ['Asia/Singapore', 'GST', 9], 'MY' => ['Asia/Kuala_Lumpur', 'SST', 6], 'TH' => ['Asia/Bangkok', 'VAT', 7],
        'ID' => ['Asia/Jakarta', 'PB1', 10], 'PH' => ['Asia/Manila', 'VAT', 12], 'VN' => ['Asia/Ho_Chi_Minh', 'VAT', 8],
        'KH' => ['Asia/Phnom_Penh', 'VAT', 10], 'LA' => ['Asia/Vientiane', 'VAT', 10], 'CN' => ['Asia/Shanghai', 'VAT', 6],
        'HK' => ['Asia/Hong_Kong', 'Tax', 0], 'TW' => ['Asia/Taipei', 'VAT', 5], 'JP' => ['Asia/Tokyo', 'Consumption Tax', 10],
        'KR' => ['Asia/Seoul', 'VAT', 10], 'AF' => ['Asia/Kabul', 'Tax', 0], 'IE' => ['Europe/Dublin', 'VAT', 13.5],
        'DE' => ['Europe/Berlin', 'MwSt', 19], 'FR' => ['Europe/Paris', 'TVA', 10], 'IT' => ['Europe/Rome', 'IVA', 10],
        'ES' => ['Europe/Madrid', 'IVA', 10], 'PT' => ['Europe/Lisbon', 'IVA', 13], 'NL' => ['Europe/Amsterdam', 'BTW', 9],
        'BE' => ['Europe/Brussels', 'TVA', 12], 'CH' => ['Europe/Zurich', 'MWST', 8.1], 'AT' => ['Europe/Vienna', 'USt', 10],
        'SE' => ['Europe/Stockholm', 'Moms', 12], 'NO' => ['Europe/Oslo', 'MVA', 25], 'DK' => ['Europe/Copenhagen', 'Moms', 25],
        'FI' => ['Europe/Helsinki', 'ALV', 14], 'PL' => ['Europe/Warsaw', 'VAT', 8], 'GR' => ['Europe/Athens', 'VAT', 13],
        'RO' => ['Europe/Bucharest', 'TVA', 9], 'CZ' => ['Europe/Prague', 'DPH', 12], 'HU' => ['Europe/Budapest', 'ÁFA', 5],
        'RU' => ['Europe/Moscow', 'VAT', 20], 'UA' => ['Europe/Kyiv', 'VAT', 20], 'TR' => ['Europe/Istanbul', 'KDV', 10],
        'IL' => ['Asia/Jerusalem', 'VAT', 18], 'EG' => ['Africa/Cairo', 'VAT', 14], 'ZA' => ['Africa/Johannesburg', 'VAT', 15],
        'NG' => ['Africa/Lagos', 'VAT', 7.5], 'KE' => ['Africa/Nairobi', 'VAT', 16], 'GH' => ['Africa/Accra', 'VAT', 15],
        'TZ' => ['Africa/Dar_es_Salaam', 'VAT', 18], 'ET' => ['Africa/Addis_Ababa', 'VAT', 15], 'MA' => ['Africa/Casablanca', 'TVA', 10],
        'DZ' => ['Africa/Algiers', 'TVA', 19], 'TN' => ['Africa/Tunis', 'TVA', 19], 'JO' => ['Asia/Amman', 'GST', 16],
        'LB' => ['Asia/Beirut', 'VAT', 11], 'IQ' => ['Asia/Baghdad', 'Tax', 0], 'IR' => ['Asia/Tehran', 'VAT', 10],
        'BR' => ['America/Sao_Paulo', 'Tax', 0], 'MX' => ['America/Mexico_City', 'IVA', 16], 'AR' => ['America/Argentina/Buenos_Aires', 'IVA', 21],
        'CL' => ['America/Santiago', 'IVA', 19], 'CO' => ['America/Bogota', 'Impoconsumo', 8], 'PE' => ['America/Lima', 'IGV', 18],
        'VE' => ['America/Caracas', 'IVA', 16], 'EC' => ['America/Guayaquil', 'IVA', 15], 'UY' => ['America/Montevideo', 'IVA', 22],
        'PY' => ['America/Asuncion', 'IVA', 10], 'BO' => ['America/La_Paz', 'IVA', 13], 'PA' => ['America/Panama', 'ITBMS', 10],
        'CR' => ['America/Costa_Rica', 'IVA', 13], 'GT' => ['America/Guatemala', 'IVA', 12], 'DO' => ['America/Santo_Domingo', 'ITBIS', 18],
        'JM' => ['America/Jamaica', 'GCT', 15], 'TT' => ['America/Port_of_Spain', 'VAT', 12.5], 'FJ' => ['Pacific/Fiji', 'VAT', 15],
        'MU' => ['Indian/Mauritius', 'VAT', 15], 'MV' => ['Indian/Maldives', 'GST', 8],
    ];
    $r = $d[strtoupper((string)$iso2)] ?? null;
    if (!$r) return null;
    return ['timezone' => $r[0], 'tax_name' => $r[1], 'tax_percent' => (float)$r[2], 'tax_on' => strtoupper($iso2) === 'IN'];
}

/**
 * Sets a new restaurant's time zone and tax to its country's usual ones (signup and Super Admin create).
 * Each column on its own, so a database without one of them still gets the rest.
 */
function applyCountryDefaults(PDO $pdo, string $restaurantId, ?string $countryName): void {
    $c = getCountryByName((string)$countryName);
    $d = $c ? getCountryLocalDefaults($c['iso2']) : null;
    if (!$d) return;
    foreach (['timezone' => $d['timezone'], 'tax_name' => $d['tax_name'], 'tax_percent' => $d['tax_percent'], 'enable_gst' => $d['tax_on'] ? 1 : 0] as $col => $val) {
        try { $pdo->prepare("UPDATE users SET $col = ? WHERE restaurant_id = ?")->execute([$val, $restaurantId]); }
        catch (Throwable $e) { error_log("applyCountryDefaults $col: " . $e->getMessage()); }
    }
}

/** The country (getCountryByName shape) a restaurant is set to, or null. Cached per request. */
function restaurantCountryInfo(PDO $pdo, ?string $restaurantId): ?array {
    static $cache = [];
    $rid = (string)$restaurantId;
    if ($rid === '') return null;
    if (!array_key_exists($rid, $cache)) {
        try {
            $st = $pdo->prepare('SELECT country FROM users WHERE restaurant_id = ? ORDER BY id LIMIT 1');
            $st->execute([$rid]);
            $cache[$rid] = getCountryByName((string)$st->fetchColumn());
        } catch (Throwable $e) { $cache[$rid] = null; }
    }
    return $cache[$rid];
}

/**
 * A phone number in full international form (+<country code><number>), using the given country for
 * numbers typed without a code. Accepts "+977 98…", "00977…", "977 98…", "98…" and a leading trunk 0
 * ("07911…" in the UK). Returns null when the country is unknown and the number has no code, so the
 * caller can fall back to its own default.
 */
function phoneToInternational(string $phone, ?array $country): ?string {
    $raw = trim($phone);
    $d = preg_replace('/\D/', '', $raw);
    if ($d === '') return null;
    if (strpos($raw, '+') === 0) return '+' . $d;
    if (strpos($d, '00') === 0) return '+' . substr($d, 2);
    if (!$country) return null;
    $cc = preg_replace('/\D/', '', $country['dial_code']);
    if (strlen($d) > $country['phone_max'] && strpos($d, $cc) === 0 && strlen($d) - strlen($cc) >= $country['phone_min']) return '+' . $d;
    if (strlen($d) === $country['phone_max'] + 1 && $d[0] === '0') $d = substr($d, 1);
    return '+' . $cc . $d;
}

/** Is this restaurant's country India (or not set, which means India)? Online payments by PhonePe work only there. */
function restaurantIsIndia(?string $countryName): bool {
    $n = trim((string)$countryName);
    return $n === '' || strcasecmp($n, 'India') === 0;
}
