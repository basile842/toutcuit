<?php
// POST — Update session settings (owner only)
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

// Build dynamic update
$allowed = ['collector_open', 'is_open', 'max_collect', 'name'];
$sets = [];
$params = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $sets[] = "$field = ?";
        $params[] = $data[$field];
    }
}

if (empty($sets)) {
    jsonError('Nothing to update');
}

$params[] = $sessionId;
$sql = 'UPDATE sessions SET ' . implode(', ', $sets) . ' WHERE id = ?';
$stmt = $db->prepare($sql);
$stmt->execute($params);

jsonResponse(['ok' => true, 'session_id' => $sessionId]);
