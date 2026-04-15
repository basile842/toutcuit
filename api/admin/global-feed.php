<?php
// GET — Global feed: CERTs + student responses across selected sessions (superadmin)
// ?sessions=CODE1,CODE2,CODE3
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$codes = $_GET['sessions'] ?? '';
if (!$codes) {
    jsonError('Missing sessions parameter');
}

$codeList = array_filter(array_map('trim', explode(',', $codes)));
if (empty($codeList)) {
    jsonError('No valid session codes');
}

$db = getDB();

// Find session IDs from codes
$placeholders = implode(',', array_fill(0, count($codeList), '?'));
$stmt = $db->prepare("SELECT id, code FROM sessions WHERE code IN ($placeholders)");
$stmt->execute($codeList);
$sessions = $stmt->fetchAll();
$sessionIds = array_column($sessions, 'id');

if (empty($sessionIds)) {
    jsonResponse(['certs' => [], 'responses' => []]);
}

$idPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));

// Get all CERTs assigned to these sessions (deduplicated)
$stmt = $db->prepare("
    SELECT DISTINCT c.*, COALESCE(c.teacher_name, t.name) AS teacher_name, sc.position
    FROM session_certs sc
    JOIN certs c ON c.id = sc.cert_id
    LEFT JOIN teachers t ON t.id = c.teacher_id
    WHERE sc.session_id IN ($idPlaceholders)
    ORDER BY sc.position
");
$stmt->execute($sessionIds);
$certs = $stmt->fetchAll();

// Get all student responses from these sessions
$stmt = $db->prepare("
    SELECT sr.user_id, sr.cert_id, sr.first_label, sr.last_label,
           sr.reliability, sr.comment, sr.created_at,
           s.code AS session_code
    FROM student_responses sr
    JOIN sessions s ON s.id = sr.session_id
    WHERE sr.session_id IN ($idPlaceholders)
    ORDER BY sr.created_at DESC
");
$stmt->execute($sessionIds);
$responses = $stmt->fetchAll();

jsonResponse(['certs' => $certs, 'responses' => $responses]);
