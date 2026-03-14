<?php
// GET — Feed of student responses for a session (public)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$code = $_GET['session'] ?? '';
if (!$code) {
    jsonError('Missing session code');
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM sessions WHERE code = ?');
$stmt->execute([$code]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}

$stmt = $db->prepare('
    SELECT sr.user_id, sr.cert_id, sr.first_label, sr.last_label,
           sr.reliability, sr.comment, sr.created_at
    FROM student_responses sr
    WHERE sr.session_id = ?
    ORDER BY sr.created_at DESC
');
$stmt->execute([$session['id']]);

jsonResponse($stmt->fetchAll());
