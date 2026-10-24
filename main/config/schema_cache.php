<?php
/**
 * Faster pages: the "does this column / table exist?" checks and the "create the tables if missing" setup
 * used to run on every request (10-25 extra database round trips per page on the customer website).
 *
 *  rgHasColumn / rgHasTable: a "yes" is remembered in tmp/schema_cache.json (columns and tables are added,
 *      never removed by the app); a "no" is asked again every time, so a column added later is seen at once.
 *  rgEnsureOnce('ensureXyzSchema', $conn): runs that setup function once per deploy (the flag is keyed on
 *      the file that defines it, which changes when new code is deployed) instead of on every request.
 */
if (!function_exists('rgHasColumn')) {

    function rgSchemaCacheFile(): string {
        $dir = __DIR__ . '/../tmp';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/schema_cache.json';
    }

    function rgSchemaKnown(string $key): bool {
        static $known = null;
        if ($known === null) {
            $known = @json_decode((string)@file_get_contents(rgSchemaCacheFile()), true);
            if (!is_array($known)) $known = [];
        }
        return !empty($known[$key]);
    }

    function rgSchemaRemember(string $key): void {
        $all = @json_decode((string)@file_get_contents(rgSchemaCacheFile()), true);
        if (!is_array($all)) $all = [];
        $all[$key] = 1;
        @file_put_contents(rgSchemaCacheFile(), json_encode($all), LOCK_EX);
    }

    function rgHasColumn(PDO $conn, string $table, string $column): bool {
        $key = 'c:' . $table . '.' . $column;
        if (rgSchemaKnown($key)) return true;
        try {
            // information_schema, not SHOW ... LIKE ?: native prepares (EMULATE_PREPARES off) refuse ? in SHOW
            $st = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            $st->execute([$table, $column]);
            $yes = (bool)$st->fetchColumn();
        } catch (Throwable $e) { $yes = false; }
        if ($yes) rgSchemaRemember($key);
        return $yes;
    }

    function rgHasTable(PDO $conn, string $table): bool {
        $key = 't:' . $table;
        if (rgSchemaKnown($key)) return true;
        try {
            $st = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $st->execute([$table]);
            $yes = (bool)$st->fetchColumn();
        } catch (Throwable $e) { $yes = false; }
        if ($yes) rgSchemaRemember($key);
        return $yes;
    }

    /** Run a "create tables / add columns if missing" function once per deploy. Never inside a transaction. */
    function rgEnsureOnce(string $fn, PDO $conn): void {
        if (!function_exists($fn)) return;
        $file = (new ReflectionFunction($fn))->getFileName();
        $key = 'e:' . $fn . ':' . @filemtime($file);
        if (rgSchemaKnown($key)) return;
        $fn($conn);
        rgSchemaRemember($key);
    }
}
