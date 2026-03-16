<?php
// POST — Duplicate an existing session (copies settings + assigned CERTs)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
$db = getDB();

requireFields($data, ['session_id', 'name']);

$sessionId = (int) $data['session_id'];
$newName = trim($data['name']);

if (!$newName) {
    jsonError('Le nom est requis');
}

// Load original session (must belong to this teacher)
$stmt = $db->prepare('SELECT * FROM sessions WHERE id = ? AND teacher_id = ?');
$stmt->execute([$sessionId, $teacherId]);
$original = $stmt->fetch();

if (!$original) {
    jsonError('Séance introuvable', 404);
}

// Check name is different
if ($newName === $original['name']) {
    jsonError('Le nom doit être différent de la séance originale');
}

// Generate unique session code
$code = '';
$attempts = 0;
do {
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $stmt = $db->prepare('SELECT 1 FROM sessions WHERE code = ?');
    $stmt->execute([$code]);
    $attempts++;
} while ($stmt->fetch() && $attempts < 10);

if ($attempts >= 10) {
    jsonError('Impossible de générer un code unique, réessayez', 500);
}

$db->beginTransaction();

try {
    // Create new session with same settings
    $stmt = $db->prepare('
        INSERT INTO sessions (teacher_id, school_id, name, code, is_open, collector_open, max_collect)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $teacherId,
        $original['school_id'],
        $newName,
        $code,
        $original['is_open'],
        $original['collector_open'],
        $original['max_collect'],
    ]);

    $newId = (int) $db->lastInsertId();

    // Copy assigned CERTs
    $stmt = $db->prepare('
        INSERT INTO session_certs (session_id, cert_id, position)
        SELECT ?, cert_id, position
        FROM session_certs
        WHERE session_id = ?
    ');
    $stmt->execute([$newId, $sessionId]);

    $db->commit();

    jsonResponse([
        'id'   => $newId,
        'name' => $newName,
        'code' => $code,
    ], 201);
} catch (Exception $e) {
    $db->rollBack();
    jsonError('Erreur lors de la duplication : ' . $e->getMessage(), 500);
}
