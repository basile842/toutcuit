<?php
// GET — Sessions for the authenticated teacher
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$stmt = $db->prepare('
    SELECT s.*, sc.name AS school_name
    FROM sessions s
    JOIN schools sc ON sc.id = s.school_id
    WHERE s.teacher_id = ?
    ORDER BY s.created_at DESC
');
$stmt->execute([$teacherId]);

jsonResponse($stmt->fetchAll());
