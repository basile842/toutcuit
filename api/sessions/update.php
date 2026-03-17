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

// Rename school if requested
if (array_key_exists('school_name', $data)) {
    $newSchoolName = trim($data['school_name']);
    if (!$newSchoolName) {
        jsonError('Le nom de l\'école est requis');
    }
    $stmt = $db->prepare('SELECT school_id FROM sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    if ($row && $row['school_id']) {
        $stmt = $db->prepare('UPDATE schools SET name = ? WHERE id = ?');
        $stmt->execute([$newSchoolName, $row['school_id']]);
    }
}

// Build dynamic update
$allowed = ['collector_open', 'is_open', 'max_collect', 'name', 'visible_links'];
$sets = [];
$params = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $sets[] = "$field = ?";
        $params[] = $data[$field];
    }
}

if (empty($sets) && !array_key_exists('school_name', $data)) {
    jsonError('Nothing to update');
}

if (!empty($sets)) {
    $params[] = $sessionId;
    $sql = 'UPDATE sessions SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

jsonResponse(['ok' => true, 'session_id' => $sessionId]);
