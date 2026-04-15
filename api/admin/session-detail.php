<?php
// GET — Aggregated session detail for superadmin (certs, responses count, collected count)
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
if (!$sessionId) {
    jsonError('Missing session_id');
}

$db = getDB();

// Session info
$stmt = $db->prepare('
    SELECT s.*, sc.name AS school_name, t.name AS teacher_name
    FROM sessions s
    LEFT JOIN schools sc ON sc.id = s.school_id
    LEFT JOIN teachers t ON t.id = s.teacher_id
    WHERE s.id = ?
');
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Séance introuvable', 404);
}

// Assigned CERTs
$stmt = $db->prepare('
    SELECT c.id, c.title, c.descriptor1, c.descriptor2, c.reliability, sc.position
    FROM session_certs sc
    JOIN certs c ON c.id = sc.cert_id
    WHERE sc.session_id = ?
    ORDER BY sc.position ASC
');
$stmt->execute([$sessionId]);
$certs = $stmt->fetchAll();

// Student responses count
$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM student_responses WHERE session_id = ?');
$stmt->execute([$sessionId]);
$responsesCount = (int) $stmt->fetch()['cnt'];

// Collected links count
$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM collected_links WHERE session_id = ?');
$stmt->execute([$sessionId]);
$collectedCount = (int) $stmt->fetch()['cnt'];

// Distinct students
$stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) AS cnt FROM student_responses WHERE session_id = ?');
$stmt->execute([$sessionId]);
$studentsCount = (int) $stmt->fetch()['cnt'];

jsonResponse([
    'session'          => $session,
    'certs'            => $certs,
    'responses_count'  => $responsesCount,
    'collected_count'  => $collectedCount,
    'students_count'   => $studentsCount,
]);
