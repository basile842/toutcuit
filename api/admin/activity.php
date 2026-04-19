<?php
// GET — Filtered slice of teacher_activity, joined with teacher name/role.
// Query params (all optional):
//   days       : lookback window in days (default 7, max 90)
//   teacher_id : restrict to one teacher
//   action     : exact action string (e.g. 'cert.save')
//   prefix     : action prefix (e.g. 'review.' to get the whole cycle)
//   limit      : page size (default 500, max 2000)
//   before_id  : cursor — return rows with id < before_id (for "load more")
// Also returns a 6-week daily histogram of all events, regardless of filters
// (used by the fixed header chart in editor.html).
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

requireEditor();
$db = getDB();

$days      = max(1, min(90, (int)($_GET['days'] ?? 7)));
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$action    = trim($_GET['action'] ?? '');
$prefix    = trim($_GET['prefix'] ?? '');
$limit     = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
$beforeId  = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;

// Build filtered feed query
// auth.login events are logged but excluded from the Activité view — live
// presence ("En ligne maintenant") already tells editors who is connected,
// so rendering every login would clutter the histogram and feed.
$where  = [
    'ta.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
    "ta.action <> 'auth.login'",
];
$params = [$days];
if ($teacherId > 0) {
    $where[]  = 'ta.teacher_id = ?';
    $params[] = $teacherId;
}
if ($action !== '') {
    $where[]  = 'ta.action = ?';
    $params[] = $action;
} elseif ($prefix !== '') {
    $where[]  = 'ta.action LIKE ?';
    $params[] = $prefix . '%';
}
if ($beforeId > 0) {
    $where[]  = 'ta.id < ?';
    $params[] = $beforeId;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT
        ta.id, ta.teacher_id, ta.action, ta.target_type, ta.target_id,
        ta.meta, ta.created_at,
        t.name AS teacher_name, t.role AS teacher_role, t.email AS teacher_email
    FROM teacher_activity ta
    LEFT JOIN teachers t ON t.id = ta.teacher_id
    $whereSql
    ORDER BY ta.id DESC
    LIMIT $limit
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Decode JSON meta so the frontend does not have to parse it twice
foreach ($events as &$ev) {
    if (!empty($ev['meta']) && is_string($ev['meta'])) {
        $decoded = json_decode($ev['meta'], true);
        $ev['meta'] = is_array($decoded) ? $decoded : null;
    }
}
unset($ev);

// 6-week daily histogram (42 days). Always returned — independent of filters —
// so the header chart reflects overall activity.
$histStmt = $db->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS n
    FROM teacher_activity
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 41 DAY)
      AND action <> 'auth.login'
    GROUP BY day
    ORDER BY day ASC
");
$histRows = $histStmt->fetchAll();
$histByDay = [];
foreach ($histRows as $r) {
    $histByDay[$r['day']] = (int)$r['n'];
}

// Fill every day in the 6-week window (including zeroes) so the chart is regular
$histogram = [];
for ($i = 41; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $histogram[] = ['day' => $day, 'n' => $histByDay[$day] ?? 0];
}

// Distinct action list (current window) so the filter dropdown reflects reality
$actStmt = $db->prepare("
    SELECT DISTINCT action
    FROM teacher_activity
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND action <> 'auth.login'
    ORDER BY action ASC
");
$actStmt->execute([$days]);
$actions = array_map(fn($r) => $r['action'], $actStmt->fetchAll());

jsonResponse([
    'events'    => $events,
    'histogram' => $histogram,
    'actions'   => $actions,
    'days'      => $days,
    'limit'     => $limit,
    'has_more'  => count($events) === $limit,
]);
