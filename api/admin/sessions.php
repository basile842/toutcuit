<?php
// GET — List all sessions across all teachers (superadmin)
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$db = getDB();

$stmt = $db->query('
    SELECT s.*, sc.name AS school_name, t.name AS teacher_name
    FROM sessions s
    LEFT JOIN schools sc ON sc.id = s.school_id
    LEFT JOIN teachers t ON t.id = s.teacher_id
    ORDER BY s.created_at DESC
');

jsonResponse($stmt->fetchAll());
