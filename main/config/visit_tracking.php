<?php
/**
 * Telling a visitor from a crawler, for the website visit counter (website/track_visit.php).
 *
 * The tracking endpoint is a plain GET with the page in the query string, so Google found it and
 * started crawling it like any other page. Every crawl wrote a row into page_visits, and the owner's
 * Analytics counted it as a person walking past their menu. On 5 Nov 2026 that was 4.2% of every
 * visit ever recorded and 10.5% of the last thirty days — on the smallest restaurants, 41% of their
 * "visitors" were Googlebot.
 *
 * robots.txt now disallows the endpoint too, but robots.txt is a request and only the polite
 * crawlers honour it, so the check here is what actually keeps the numbers clean.
 */

/** Crawlers that call themselves honestly. A bot that lies about its user agent is not solvable here. */
const VISIT_BOT_WORDS = ['bot', 'crawler', 'spider', 'slurp', 'facebookexternalhit', 'embedly',
    'quora link preview', 'ia_archiver', 'headlesschrome', 'python-requests', 'curl/', 'wget',
    'go-http-client', 'okhttp', 'apache-httpclient', 'java/', 'lighthouse', 'pagespeed', 'preview'];

/** Is this user agent a crawler rather than a person? An empty agent counts as one: no real browser sends none. */
function visitIsBot(?string $ua): bool
{
    $ua = trim((string)$ua);
    if ($ua === '') return true;
    $low = strtolower($ua);
    foreach (VISIT_BOT_WORDS as $w) if (str_contains($low, $w)) return true;
    return false;
}
