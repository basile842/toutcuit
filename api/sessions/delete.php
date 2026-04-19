<?php
// POST — Delete a session (owner only)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
$db = getDB();

requireFields($data, ['session_id']);

$sessionId = (int) $data['session_id'];

// Verify ownership
$stmt = $db->prepare('SELECT id FROM sessions WHERE id = ? AND teacher_id = ?');
$stmt->execute([$sessionId, $teacherId]);
if (!$stmt->fetch()) {
    jsonError('Session not found or not yours', 403);
}

// Delete (cascades to session_certs, student_responses, collected_links)
$stmt = $db->prepare('DELETE FROM sessions WHERE id = ?');
$stmt->execute([$sessionId]);

cleanOrphanedSchools($db);

logActivity($teacherId, 'session.delete', 'session', $sessionId);

jsonResponse(['deleted' => true, 'session_id' => $sessionId]);
