<?php
// POST — Student submits a collected link (no auth)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['session_code', 'user_id', 'url']);

$db = getDB();

// Resolve session
$stmt = $db->prepare('SELECT id, is_open, collector_open, max_collect FROM sessions WHERE code = ?');
$stmt->execute([$data['session_code']]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}
if (!(int) $session['is_open']) {
    jsonError('Session is closed');
}

$sessionId = (int) $session['id'];
$userId = trim($data['user_id']);
$maxCollect = (int) $session['max_collect'];

// Check quota
$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM collected_links WHERE session_id = ? AND user_id = ?');
$stmt->execute([$sessionId, $userId]);
$count = (int) $stmt->fetch()['cnt'];

if ($count >= $maxCollect) {
    jsonError("Maximum links reached ($maxCollect)");
}

$stmt = $db->prepare('
    INSERT INTO collected_links (session_id, user_id, url, comment)
    VALUES (?, ?, ?, ?)
');
$stmt->execute([
    $sessionId,
    $userId,
    trim($data['url']),
    $data['comment'] ?? null,
]);

jsonResponse(['ok' => true, 'remaining' => $maxCollect - $count - 1]);
