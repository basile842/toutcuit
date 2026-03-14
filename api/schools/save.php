<?php
// POST — Create a new school or join an existing one
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
$db = getDB();

// If school_id is provided, join existing school
if (!empty($data['school_id'])) {
    $schoolId = (int) $data['school_id'];

    // Check school exists
    $stmt = $db->prepare('SELECT id, name FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    if (!$school) {
        jsonError('School not found', 404);
    }

    // Check not already joined
    $stmt = $db->prepare('SELECT 1 FROM teacher_school WHERE teacher_id = ? AND school_id = ?');
    $stmt->execute([$teacherId, $schoolId]);
    if (!$stmt->fetch()) {
        $stmt = $db->prepare('INSERT INTO teacher_school (teacher_id, school_id) VALUES (?, ?)');
        $stmt->execute([$teacherId, $schoolId]);
    }

    jsonResponse($school);
}

// Otherwise, create a new school
requireFields($data, ['name']);
$name = trim($data['name']);

$stmt = $db->prepare('INSERT INTO schools (name) VALUES (?)');
$stmt->execute([$name]);
$schoolId = (int) $db->lastInsertId();

// Auto-join the creator
$stmt = $db->prepare('INSERT INTO teacher_school (teacher_id, school_id) VALUES (?, ?)');
$stmt->execute([$teacherId, $schoolId]);

jsonResponse(['id' => $schoolId, 'name' => $name], 201);
