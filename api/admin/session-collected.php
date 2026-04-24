<?php
// GET — Collected links for a session (editor-only view, independent of ownership).
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
if (!$sessionId) {
    jsonError('Missing session_id');
}

$db = getDB();

$stmt = $db->prepare('SELECT id FROM sessions WHERE id = ?');
$stmt->execute([$sessionId]);
if (!$stmt->fetch()) {
    jsonError('Séance introuvable', 404);
}

$stmt = $db->prepare('
    SELECT id, user_id, url, comment, created_at
    FROM collected_links
    WHERE session_id = ?
    ORDER BY created_at DESC
');
$stmt->execute([$sessionId]);

jsonResponse($stmt->fetchAll());
