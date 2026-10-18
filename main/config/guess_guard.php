<?php
/**
 * Stops guessing order numbers. Order numbers are ORD-<date>-<4 digits>, so someone who knows a phone number
 * could try them all to open that customer's tracking page (address, rider's live location), review, scratch
 * card or payment. After RG_GUESS_MAX wrong order-number + phone pairs from one internet address in an hour,
 * that address gets nothing (not even a right guess) until the hour is over. Real customers open their own
 * order from a link and never get close.
 *
 * Used by: website/track.php, api/get_tracking_status.php, api/submit_feedback.php, api/scratch_card.php,
 * api/phonepe_order_payment.php.
 */
if (!function_exists('rgGuessBlocked')) {
    if (!defined('RG_GUESS_MAX')) define('RG_GUESS_MAX', 25);

    function rgGuessFile(): string {
        $dir = __DIR__ . '/../tmp/rate_limits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/guess_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
    }

    function rgGuessState(): array {
        $d = @json_decode((string)@file_get_contents(rgGuessFile()), true);
        if (!is_array($d) || ($d['since'] ?? 0) < time() - 3600) $d = ['since' => time(), 'misses' => 0];
        return $d;
    }

    /** True when this address made too many wrong guesses this hour (answer as if nothing was found). */
    function rgGuessBlocked(): bool {
        return rgGuessState()['misses'] >= RG_GUESS_MAX;
    }

    /** Count one wrong order-number / phone pair. */
    function rgGuessMiss(): void {
        $d = rgGuessState();
        $d['misses']++;
        @file_put_contents(rgGuessFile(), json_encode($d), LOCK_EX);
    }
}
