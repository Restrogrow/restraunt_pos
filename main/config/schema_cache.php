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

    /**
     * One file per database, not per installation.
     *
     * The local copy and the server are different databases behind the same code, and a single
     * shared file described whichever was connected first: a column present on one was then
     * reported as present on the other, and the ensure that would have added it never ran. That is
     * how a database ended up being served with no website_banners.placement and no
     * customers.birthday while the notes said both had been dealt with.
     *
     * A restored dump is still a database changing under the same name, so after restoring one the
     * file for it has to be deleted - but it is now obvious which file that is.
     */
    function rgSchemaCacheFile(): string {
        $dir = __DIR__ . '/../tmp';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // the DSN db_connection.php connected with, reduced to host and database name: cron jobs
        // reach the same server over TCP where the web requests use the socket, and 127.0.0.1 and
        // localhost would otherwise each keep their own half-built copy of the same notes
        $scope = (string)($GLOBALS['rg_db_scope'] ?? '');
        if ($scope !== '') {
            $host = ''; $name = '';
            if (preg_match('/host=([^;]*)/i', $scope, $m)) $host = strtolower(trim($m[1]));
            if (preg_match('/dbname=([^;]*)/i', $scope, $m)) $name = trim($m[1]);
            if ($host === '127.0.0.1' || $host === '::1') $host = 'localhost';
            $scope = $host . '/' . $name;
        }
        return $dir . '/schema_cache' . ($scope === '' ? '' : '_' . substr(sha1($scope), 0, 12)) . '.json';
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

    /**
     * Run a "create tables / add columns if missing" function once per deploy. Never inside a transaction.
     *
     * An ensure that returns false is not remembered, so it is tried again on the next request. Ensures
     * catch their own database errors, so without that we cannot tell a real failure from a success: one
     * ALTER that failed (table not there yet, lock timeout) would be marked done and the column would stay
     * missing until the defining file was next edited. Returning nothing still counts as success, so the
     * ensures that don't report back are unaffected.
     */
    function rgEnsureOnce(string $fn, PDO $conn): void {
        if (!function_exists($fn)) return;
        $file = (new ReflectionFunction($fn))->getFileName();
        $key = 'e:' . $fn . ':' . @filemtime($file);
        if (rgSchemaKnown($key)) return;
        if ($fn($conn) === false) return;
        rgSchemaRemember($key);
    }
}
