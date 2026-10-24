<?php
/**
 * Bikram Sambat (BS) dates for Nepal bills and reports, and Nepal's fiscal year (1 Shrawan to the end of Asar).
 * BS month lengths are fixed each year by Nepal's calendar committee, so they come from a table
 * (BS 2075–2089, from github.com/ernilambar/nepali-date), counted from 1 Baisakh 2075 = 14 April 2018.
 * Outside the table the functions return null and callers show the English date only.
 */

const NP_BS_MONTHS = ['Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Ashwin', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
const NP_BS_DAYS = [
    2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2076 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2077 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2078 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2079 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2081 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2082 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2083 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2084 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
    2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
    2086 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
    2087 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
    2088 => [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
    2089 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
];

/** BS [year, month 1–12, day] for an English date ('Y-m-d' or anything strtotime reads), or null outside the table. */
function npBsFromAd(string $date): ?array
{
    $ts = strtotime(substr($date, 0, 10) . ' 00:00:00 UTC');
    if ($ts === false) return null;
    $days = intdiv($ts - strtotime('2018-04-14 00:00:00 UTC'), 86400);
    if ($days < 0) return null;
    foreach (NP_BS_DAYS as $y => $months) {
        foreach ($months as $i => $n) {
            if ($days < $n) return [$y, $i + 1, $days + 1];
            $days -= $n;
        }
    }
    return null;
}

/** "2083-07-08" (or "8 Kartik 2083" with $long), null outside the table. */
function npBsFormat(string $date, bool $long = false): ?string
{
    $bs = npBsFromAd($date);
    if (!$bs) return null;
    return $long ? $bs[2] . ' ' . NP_BS_MONTHS[$bs[1] - 1] . ' ' . $bs[0] : sprintf('%04d-%02d-%02d', $bs[0], $bs[1], $bs[2]);
}

/** Nepal's fiscal year for a date as "2083/84": it starts on 1 Shrawan (BS month 4). Null outside the table. */
function npFiscalYear(string $date): ?string
{
    $bs = npBsFromAd($date);
    if (!$bs) return null;
    $start = $bs[1] >= 4 ? $bs[0] : $bs[0] - 1;
    return $start . '/' . substr((string)($start + 1), -2);
}

/** English date of 1 Shrawan that starts the fiscal year "2083/84" ('Y-m-d'), for "this fiscal year" queries. */
function npFiscalYearStart(string $fy): ?string
{
    $y = (int)substr($fy, 0, 4);
    if (!isset(NP_BS_DAYS[$y])) return null;
    $days = 0;
    foreach (NP_BS_DAYS as $yy => $months) {
        if ($yy === $y) { $days += $months[0] + $months[1] + $months[2]; break; }
        $days += array_sum($months);
    }
    return gmdate('Y-m-d', strtotime('2018-04-14 00:00:00 UTC') + $days * 86400);
}
