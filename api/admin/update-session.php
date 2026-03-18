<?php
// POST — Update session settings (superadmin, no auth)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['session_id']);

$sessionId = (int) $data['session_id'];
$db = getDB();

// Verify session exists
$stmt = $db->prepare('SELECT id FROM sessions WHERE id = ?');
$stmt->execute([$sessionId]);
if (!$stmt->fetch()) {
    jsonError('Séance introuvable', 404);
}

$allowed = ['is_open', 'collector_open', 'max_collect', 'name', 'visible_links'];
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
