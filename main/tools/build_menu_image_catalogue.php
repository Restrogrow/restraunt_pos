<?php
/**
 * Reads the images/ photo library with Gemini and writes down what each photo is of, so menu items
 * can be matched to it by dish rather than by file name. See includes/menu_image_catalogue.php.
 *
 *   php main/tools/build_menu_image_catalogue.php              # only photos not catalogued yet
 *   php main/tools/build_menu_image_catalogue.php --all        # start again from scratch
 *   php main/tools/build_menu_image_catalogue.php --no-vision  # skip photos whose name says nothing
 *   php main/tools/build_menu_image_catalogue.php --status     # what is catalogued so far
 *
 * Safe to stop and re-run: progress is saved after every batch. Needs GEMINI_API_KEY in main/.env.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/menu_image_catalogue.php';
require_once __DIR__ . '/../includes/menu_image_matcher.php';

$args = array_slice($argv, 1);
$has = function ($flag) use ($args) { return in_array($flag, $args, true); };

if ($has('--status')) {
    $s = menu_image_catalogue_stats();
    echo 'photos in the library : ' . $s['total'] . "\n";
    echo 'catalogued            : ' . $s['known'] . "\n";
    echo 'guessed from the name : ' . ($s['total'] - $s['known']) . "\n";
    echo 'catalogue file        : ' . $s['file'] . ($s['built'] ? ' (' . $s['built'] . ')' : ' (not built yet)') . "\n";
    exit(0);
}

$started = time();
$report = menu_image_catalogue_build(
    function ($line) { echo $line . "\n"; },
    !$has('--all'),
    !$has('--no-vision')
);

if (empty($report['ok'])) {
    fwrite(STDERR, $report['message'] . "\n");
    exit(1);
}
echo 'done in ' . (time() - $started) . "s — " . $report['catalogued'] . " photos catalogued\n";
echo $report['file'] . "\n";
