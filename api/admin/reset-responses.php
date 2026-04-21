<?php
// POST — Delete student responses for a session (optionally filtered by user_id)
// Allowed for the session owner or any editor.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['session_code']);

$db = getDB();

$stmt = $db->prepare('SELECT id, teacher_id FROM sessions WHERE code = ?');
$stmt->execute([$data['session_code']]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}

if ((int) $session['teacher_id'] !== $teacherId) {
    $roleStmt = $db->prepare('SELECT role FROM teachers WHERE id = ?');
    $roleStmt->execute([$teacherId]);
    $role = $roleStmt->fetchColumn();
    if ($role !== 'editor') {
        jsonError('Accès refusé', 403);
    }
}

$sessionId = (int) $session['id'];
$userId = isset($data['user_id']) ? trim($data['user_id']) : null;

if ($userId) {
    $stmt = $db->prepare('DELETE FROM student_responses WHERE session_id = ? AND user_id = ?');
    $stmt->execute([$sessionId, $userId]);
} else {
    $stmt = $db->prepare('DELETE FROM student_responses WHERE session_id = ?');
    $stmt->execute([$sessionId]);
}

jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
