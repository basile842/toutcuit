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

// Resolve school_id: use a different school if school_name is provided
$schoolId = $original['school_id'];
if (!empty($data['school_name'])) {
    $schoolName = trim($data['school_name']);
    // Look for existing school with this name
    $stmt = $db->prepare('SELECT id FROM schools WHERE name = ?');
    $stmt->execute([$schoolName]);
    $existing = $stmt->fetch();
    if ($existing) {
        $schoolId = (int) $existing['id'];
    } else {
        // Create the school
        $stmt = $db->prepare('INSERT INTO schools (name) VALUES (?)');
        $stmt->execute([$schoolName]);
        $schoolId = (int) $db->lastInsertId();
    }
}

// Check at least name or school is different
$originalSchoolId = (int) $original['school_id'];
if ($newName === $original['name'] && $schoolId === $originalSchoolId) {
    jsonError('Le nom de la séance ou de l\'école doit être différent de l\'original');
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
        INSERT INTO sessions (teacher_id, school_id, name, code, is_open, collector_open, max_collect, visible_links)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $teacherId,
        $schoolId,
        $newName,
        $code,
        $original['is_open'],
        $original['collector_open'],
        $original['max_collect'],
        $original['visible_links'] ?? 0,
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
