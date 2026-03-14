<?php
// GET — Teacher's own CERTs + shared (fonds commun)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

// Own CERTs + all shared CERTs (deduplicated by UNION)
$stmt = $db->prepare('
    SELECT c.*, t.name AS teacher_name
    FROM certs c
    JOIN teachers t ON t.id = c.teacher_id
    WHERE c.teacher_id = ? OR c.is_shared = 1
    ORDER BY c.created_at DESC
');
$stmt->execute([$teacherId]);

$certs = $stmt->fetchAll();

// Tag each cert as "mine" or not
foreach ($certs as &$cert) {
    $cert['is_mine'] = ((int) $cert['teacher_id'] === $teacherId);
}

jsonResponse($certs);
