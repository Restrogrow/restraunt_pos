<?php
/**
 * Public facts the marketing pages show, so they never disagree with sign-up:
 * the free-trial length (Super Admin › Settings › trial days). Read by index.html, faq/, about/ and the
 * blog through [data-trial-days]. No login, nothing private, cached for 10 minutes.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=600');
$out = ['trial_days' => 30];
try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/onboarding.php';
    $out['trial_days'] = obTrialDays(getConnection());
} catch (Throwable $e) {
    error_log('[public_info] ' . $e->getMessage());
}
echo json_encode($out);
