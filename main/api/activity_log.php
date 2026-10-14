<?php
/**
 * Activity log for the owner (Staff › Activity log, assets/js/activity-log.js). Owner only.
 * GET: group (orders, payments, …), person, from, to (Y-m-d), q (search), before (id, for "load more").
 * Entries are written by logActivity() (config/activity_log.php).
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/activity_log.php';

$role = getUserRole();
$restaurant_id = $_SESSION['restaurant_id'] ?? null;
if (!$restaurant_id || !in_array($role, [ROLE_ADMIN, 'Admin', 'Branch Admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the owner can see the activity log.']);
    exit();
}
session_write_close();

try {
    $conn = function_exists('getConnection') ? getConnection() : $pdo;
    if (!activityEnsure($conn)) throw new Exception('Activity log is not available');

    $groups = activityGroups();
    $w = ['restaurant_id = ?']; $p = [$restaurant_id];
    $group = (string)($_GET['group'] ?? '');
    if ($group !== '' && isset($groups[$group])) { $w[] = 'action LIKE ?'; $p[] = $group . '.%'; }
    $person = trim((string)($_GET['person'] ?? ''));
    if ($person !== '') { $w[] = 'actor_name = ?'; $p[] = $person; }
    $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'created_at >= ?'; $p[] = $from . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'created_at <= ?'; $p[] = $to . ' 23:59:59'; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $w[] = 'summary LIKE ?'; $p[] = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 80)) . '%'; }
    $before = (int)($_GET['before'] ?? 0);
    $listW = $w; $listP = $p;
    if ($before > 0) { $listW[] = 'id < ?'; $listP[] = $before; }

    $limit = 60;
    $st = $conn->prepare('SELECT id, actor_name, actor_role, action, entity, entity_id, summary, details, created_at FROM activity_log WHERE ' . implode(' AND ', $listW) . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
    $st->execute($listP);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    foreach ($rows as &$r) {
        $r['group'] = explode('.', $r['action'])[0];
        $r['details'] = $r['details'] ? json_decode($r['details'], true) : null;
    }
    unset($r);

    // the filters: who appears in the log, and how many entries each group has (same dates and person)
    $people = $conn->prepare('SELECT actor_name, MAX(actor_role) AS actor_role, COUNT(*) AS n FROM activity_log WHERE restaurant_id = ? GROUP BY actor_name ORDER BY n DESC LIMIT 50');
    $people->execute([$restaurant_id]);
    $cw = array_values(array_filter($w, function ($c) { return strpos($c, 'action LIKE') !== 0; }));
    $cp = $p; if ($group !== '' && isset($groups[$group])) { array_splice($cp, array_search($group . '.%', $cp, true), 1); }
    $counts = $conn->prepare("SELECT SUBSTRING_INDEX(action, '.', 1) AS g, COUNT(*) AS n FROM activity_log WHERE " . implode(' AND ', $cw) . ' GROUP BY g');
    $counts->execute($cp);
    $byGroup = []; foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $c) $byGroup[$c['g']] = (int)$c['n'];

    echo json_encode(['success' => true, 'entries' => $rows, 'more' => $more, 'people' => $people->fetchAll(PDO::FETCH_ASSOC),
        'groups' => $groups, 'counts' => $byGroup], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('activity_log.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load the activity log.']);
}
