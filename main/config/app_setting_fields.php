<?php
/**
 * The restaurant settings that can be saved one field at a time (users columns): api/app_settings.php (the apps) and
 * the API's website tools (mcp/tools/website.php) both use this list and these checks.
 */
if (defined('APP_SETTING_FIELDS')) return;

// field => type. Anything not listed here can't be read or written through api/app_settings.php.
const APP_SETTING_FIELDS = [
    'restaurant_name' => 'text', 'owner_name' => 'text', 'description' => 'longtext',
    'phone' => 'phone', 'email' => 'email', 'address' => 'longtext', 'google_maps_link' => 'url',
    'opening_hours' => 'hours',
    'enable_delivery' => 'bool', 'enable_takeaway' => 'bool', 'enable_dinein' => 'bool',
    'enable_reservations' => 'bool', 'cod_enabled' => 'bool',
    'minimum_order_value' => 'money', 'packaging_charge' => 'money',
    'delivery_radius_km' => 'money', 'enable_km_delivery' => 'bool', 'delivery_rate_per_km' => 'money',
    'enable_gst' => 'bool', 'tax_name' => 'text', 'tax_percent' => 'percent',
    'show_business_id' => 'bool', 'business_id_label' => 'text', 'business_id_no' => 'upper',
    'instagram_link' => 'url', 'facebook_link' => 'url', 'twitter_link' => 'url',
    'youtube_link' => 'url', 'linkedin_link' => 'url',
    'call_waiter_enabled' => 'bool',
];

function appSettingValue(string $field, string $type, $raw) {
    if (is_array($raw)) throw new InvalidArgumentException('Invalid value for ' . str_replace('_', ' ', $field));
    $v = is_string($raw) ? trim($raw) : $raw;
    switch ($type) {
        case 'bool':
            return in_array((string)$v, ['1', 'true', 'on'], true) ? 1 : 0;
        case 'money':
            if ($v === '' || $v === null) return 0;
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000) throw new InvalidArgumentException("Enter a valid number for " . str_replace('_', ' ', $field));
            return round((float)$v, 2);
        case 'percent':
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) throw new InvalidArgumentException('Tax must be between 0 and 100%');
            return round((float)$v, 2);
        case 'email':
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address');
            return mb_substr($v, 0, 190);
        case 'phone':
            if ($v !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $v)) throw new InvalidArgumentException('Enter a valid phone number');
            return $v;
        case 'url':
            if ($v !== '' && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Enter a valid link for ' . str_replace(['_link', '_'], ['', ' '], $field));
            return mb_substr($v, 0, 500);
        case 'hours':
            $h = json_decode((string)$v, true);
            if (!is_array($h)) throw new InvalidArgumentException('Business hours could not be read');
            $out = [];
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
                $day = $h[$d] ?? [];
                $fmt = fn($t, $def) => preg_match('/^(0?[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$/', (string)$t) ? (string)$t : $def;
                $out[$d] = ['open' => !empty($day['open']), 'opening' => $fmt($day['opening'] ?? '', '09:00 AM'), 'closing' => $fmt($day['closing'] ?? '', '11:00 PM')];
            }
            return json_encode($out);
        case 'upper':
            return mb_substr(strtoupper($v), 0, 60);
        case 'longtext':
            return mb_substr((string)$v, 0, 2000);
        default:
            if ($field === 'restaurant_name' && $v === '') throw new InvalidArgumentException('Restaurant name cannot be empty');
            return mb_substr((string)$v, 0, 190);
    }
}

