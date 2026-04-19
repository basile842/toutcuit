<?php
// POST — Create a new session
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
$db = getDB();

requireFields($data, ['name', 'school_id']);

$schoolId = (int) $data['school_id'];

// Verify teacher belongs to this school
$stmt = $db->prepare('SELECT 1 FROM teacher_school WHERE teacher_id = ? AND school_id = ?');
$stmt->execute([$teacherId, $schoolId]);
if (!$stmt->fetch()) {
    jsonError('You do not belong to this school', 403);
}

// Generate unique session code (6 uppercase alphanumeric chars)
$code = '';
$attempts = 0;
do {
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $stmt = $db->prepare('SELECT 1 FROM sessions WHERE code = ?');
    $stmt->execute([$code]);
    $attempts++;
} while ($stmt->fetch() && $attempts < 10);

if ($attempts >= 10) {
    jsonError('Could not generate unique code, try again', 500);
}

$name = trim($data['name']);
$maxCollect = (int) ($data['max_collect'] ?? 2);

$stmt = $db->prepare('
    INSERT INTO sessions (teacher_id, school_id, name, code, max_collect)
    VALUES (?, ?, ?, ?, ?)
');
$stmt->execute([$teacherId, $schoolId, $name, $code, $maxCollect]);

$newSessionId = (int) $db->lastInsertId();
logActivity($teacherId, 'session.create', 'session', $newSessionId, ['name' => $name, 'code' => $code]);

jsonResponse([
    'id'   => $newSessionId,
    'name' => $name,
    'code' => $code,
], 201);
