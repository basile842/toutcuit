<?php
// POST — Duplicate a session to another teacher, including student responses & collected links
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
$db = getDB();

requireFields($data, ['session_id', 'target_teacher_id']);

$sessionId       = (int) $data['session_id'];
$targetTeacherId = (int) $data['target_teacher_id'];
$newName         = trim($data['name'] ?? '');
$newSchoolName   = trim($data['school_name'] ?? '');

// Validate target teacher exists
$stmt = $db->prepare('SELECT id, name FROM teachers WHERE id = ?');
$stmt->execute([$targetTeacherId]);
$targetTeacher = $stmt->fetch();

if (!$targetTeacher) {
    jsonError('Enseignant-e introuvable', 404);
}

// Load original session
$stmt = $db->prepare('
    SELECT s.*, sc.name AS school_name
    FROM sessions s
    LEFT JOIN schools sc ON sc.id = s.school_id
    WHERE s.id = ?
');
$stmt->execute([$sessionId]);
$original = $stmt->fetch();

if (!$original) {
    jsonError('Séance introuvable', 404);
}

if (!$newName) {
    $newName = $original['name'] . ' (copie)';
}

// Resolve school_id from school_name (find existing case-insensitive match, otherwise create)
$schoolId = $original['school_id'];
if ($newSchoolName !== '' && strcasecmp($newSchoolName, (string) $original['school_name']) !== 0) {
    $stmt = $db->prepare('SELECT id FROM schools WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$newSchoolName]);
    $existing = $stmt->fetch();
    if ($existing) {
        $schoolId = (int) $existing['id'];
    } else {
        $stmt = $db->prepare('INSERT INTO schools (name) VALUES (?)');
        $stmt->execute([$newSchoolName]);
        $schoolId = (int) $db->lastInsertId();
    }
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
    // Create new session
    $stmt = $db->prepare('
        INSERT INTO sessions (teacher_id, school_id, name, code, is_open, collector_open, max_collect, visible_links)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $targetTeacherId,
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

    // Copy student responses (regenerate dedup_key for new session)
    $stmt = $db->prepare('
        SELECT user_id, cert_id, first_label, last_label, reliability, comment, created_at
        FROM student_responses
        WHERE session_id = ?
    ');
    $stmt->execute([$sessionId]);
    $responses = $stmt->fetchAll();

    if ($responses) {
        $ins = $db->prepare('
            INSERT INTO student_responses (session_id, user_id, cert_id, first_label, last_label, reliability, comment, dedup_key, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($responses as $r) {
            $dedupKey = $newId . '_' . $r['user_id'] . '_' . $r['cert_id'];
            $ins->execute([
                $newId,
                $r['user_id'],
                $r['cert_id'],
                $r['first_label'],
                $r['last_label'],
                $r['reliability'],
                $r['comment'],
                $dedupKey,
                $r['created_at'],
            ]);
        }
    }

    // Copy collected links
    $stmt = $db->prepare('
        INSERT INTO collected_links (session_id, user_id, url, comment, created_at)
        SELECT ?, user_id, url, comment, created_at
        FROM collected_links
        WHERE session_id = ?
    ');
    $stmt->execute([$newId, $sessionId]);

    $db->commit();

    jsonResponse([
        'id'               => $newId,
        'name'             => $newName,
        'code'             => $code,
        'responses_copied' => count($responses),
    ], 201);
} catch (Exception $e) {
    $db->rollBack();
    jsonError('Erreur lors de la duplication : ' . $e->getMessage(), 500);
}
