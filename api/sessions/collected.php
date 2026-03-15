<?php
// GET — List collected links for a session (auth required)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$sessionId = $_GET['session_id'] ?? null;
if (!$sessionId) {
    jsonError('Missing session_id');
}

$sessionId = (int) $sessionId;

// Verify teacher owns this session
$stmt = $db->prepare('SELECT id FROM sessions WHERE id = ? AND teacher_id = ?');
$stmt->execute([$sessionId, $teacherId]);
if (!$stmt->fetch()) {
    jsonError('Session introuvable', 403);
}

$stmt = $db->prepare('
    SELECT id, user_id, url, comment, created_at
    FROM collected_links
    WHERE session_id = ?
    ORDER BY created_at DESC
');
$stmt->execute([$sessionId]);

jsonResponse($stmt->fetchAll());
