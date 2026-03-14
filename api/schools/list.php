<?php
// GET — Schools for the authenticated teacher
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$stmt = $db->prepare('
    SELECT s.id, s.name, s.created_at
    FROM schools s
    JOIN teacher_school ts ON ts.school_id = s.id
    WHERE ts.teacher_id = ?
    ORDER BY s.name
');
$stmt->execute([$teacherId]);

jsonResponse($stmt->fetchAll());
