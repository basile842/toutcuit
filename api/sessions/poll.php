<?php
// GET — Lightweight polling endpoint for live updates
// Returns minimal data: visible_links count + response_count + latest response timestamp
// Used by liens.html (students) and feed.html (teachers) for efficient polling
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$code = $_GET['session'] ?? '';
if (!$code) {
    jsonError('Missing session code');
}

$db = getDB();

$stmt = $db->prepare('SELECT id, visible_links FROM sessions WHERE code = ?');
$stmt->execute([$code]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}

$sessionId = (int) $session['id'];

// Count student responses
$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM student_responses WHERE session_id = ?');
$stmt->execute([$sessionId]);
$responseCount = (int) $stmt->fetch()['cnt'];

// Latest response timestamp
$stmt = $db->prepare('SELECT MAX(created_at) AS latest FROM student_responses WHERE session_id = ?');
$stmt->execute([$sessionId]);
$latest = $stmt->fetch()['latest'] ?? null;

// Build ETag from the changing values
$etag = '"' . md5($session['visible_links'] . '|' . $responseCount . '|' . ($latest ?? '')) . '"';

// Check If-None-Match for 304 support
$clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($clientEtag === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('ETag: ' . $etag);
jsonResponse([
    'visible_links'  => (int) $session['visible_links'],
    'response_count' => $responseCount,
    'last_update'    => $latest,
]);
