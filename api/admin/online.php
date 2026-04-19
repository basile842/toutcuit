<?php
// GET — Teachers whose last_seen_at is within the last N minutes (default 5).
// Used by the "En ligne maintenant" panel of the editor activity tool.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

requireEditor();
$db = getDB();

$minutes = max(1, min(60, (int)($_GET['minutes'] ?? 5)));

$stmt = $db->prepare("
    SELECT id, email, name, role, last_seen_at
    FROM teachers
    WHERE last_seen_at IS NOT NULL
      AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ORDER BY last_seen_at DESC
");
$stmt->execute([$minutes]);

jsonResponse([
    'minutes' => $minutes,
    'now'     => date('Y-m-d H:i:s'),
    'online'  => $stmt->fetchAll(),
]);
