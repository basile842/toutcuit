<?php
// POST — Delete all responses for a given user in a session (no auth, same as submit)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['session_code', 'user_id']);

$db = getDB();

$stmt = $db->prepare('SELECT id FROM sessions WHERE code = ?');
$stmt->execute([$data['session_code']]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}

$stmt = $db->prepare('DELETE FROM student_responses WHERE session_id = ? AND user_id = ?');
$stmt->execute([$session['id'], trim($data['user_id'])]);

jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
