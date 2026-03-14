<?php
// POST — Student submits a response (no auth, identified by session code + user_id)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['session_code', 'user_id', 'cert_id']);

$db = getDB();

// Resolve session by code
$stmt = $db->prepare('SELECT id, is_open FROM sessions WHERE code = ?');
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
$certId = (int) $data['cert_id'];

// Build dedup key to prevent duplicate submissions
$dedupKey = $data['dedup_key'] ?? "{$sessionId}_{$userId}_{$certId}";

$stmt = $db->prepare('
    INSERT INTO student_responses (session_id, user_id, cert_id, first_label, last_label, reliability, comment, dedup_key)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        last_label = VALUES(last_label),
        reliability = VALUES(reliability),
        comment = VALUES(comment)
');
$stmt->execute([
    $sessionId,
    $userId,
    $certId,
    $data['first_label'] ?? null,
    $data['last_label'] ?? null,
    $data['reliability'] ?? null,
    $data['comment'] ?? null,
    $dedupKey,
]);

jsonResponse(['ok' => true]);
