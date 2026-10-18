<?php
/**
 * Is the restaurant open right now? One rule for the website header, order placement and anywhere else.
 * Hours are the users.opening_hours JSON: {"monday": {"open": true, "opening": "06:00 PM", "closing": "02:00 AM"}, ...}.
 * A shift that ends after midnight (closing at or before opening) belongs to the day it started, so at
 * 1:00 AM on Saturday, Friday's 6 PM - 2 AM shift still counts.
 */
if (!function_exists('rgIsOpenNow')) {
    function rgIsOpenNow(array $hours, DateTimeZone $tz, ?DateTime $now = null): bool {
        $now = $now ?: new DateTime('now', $tz);
        foreach ([0, -1] as $shift) {   // today's shift, then yesterday's (it may run past midnight)
            $dayStart = (clone $now)->setTime(0, 0)->modify($shift . ' day');
            $d = $hours[strtolower($dayStart->format('l'))] ?? null;
            if (!$d || empty($d['open'])) continue;
            try {
                $open = new DateTime($dayStart->format('Y-m-d') . ' ' . ($d['opening'] ?? '09:00 AM'), $tz);
                $close = new DateTime($dayStart->format('Y-m-d') . ' ' . ($d['closing'] ?? '10:00 PM'), $tz);
            } catch (Exception $e) { continue; }
            if ($close <= $open) $close->modify('+1 day');
            if ($now >= $open && $now < $close) return true;
        }
        return false;
    }
}
