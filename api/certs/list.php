<?php
// GET — All CERTs (tagged with is_mine for the authenticated teacher)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$stmt = $db->prepare('
    SELECT c.*, COALESCE(c.teacher_name, t.name) AS teacher_name
    FROM certs c
    LEFT JOIN teachers t ON t.id = c.teacher_id
    ORDER BY c.created_at DESC
');
$stmt->execute();

$certs = $stmt->fetchAll();

// Tag each cert as "mine" or not
foreach ($certs as &$cert) {
    $cert['is_mine'] = ((int) $cert['teacher_id'] === $teacherId);
}

jsonResponse($certs);
