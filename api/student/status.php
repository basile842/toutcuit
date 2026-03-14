<?php
// GET — Session status for a student (public)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$code = $_GET['session'] ?? '';
$userId = $_GET['user'] ?? '';

if (!$code) {
    jsonError('Missing session code');
}

$db = getDB();

$stmt = $db->prepare('SELECT id, is_open, collector_open, max_collect FROM sessions WHERE code = ?');
$stmt->execute([$code]);
$session = $stmt->fetch();

if (!$session) {
    jsonError('Session not found', 404);
}

$result = [
    'is_open'        => (bool) $session['is_open'],
    'collector_open' => (bool) $session['collector_open'],
    'max_collect'    => (int) $session['max_collect'],
];

// If user_id provided, include their stats
if ($userId) {
    $sessionId = (int) $session['id'];

    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM student_responses WHERE session_id = ? AND user_id = ?');
    $stmt->execute([$sessionId, $userId]);
    $result['responses_count'] = (int) $stmt->fetch()['cnt'];

    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM collected_links WHERE session_id = ? AND user_id = ?');
    $stmt->execute([$sessionId, $userId]);
    $linksCount = (int) $stmt->fetch()['cnt'];
    $result['collected_count'] = $linksCount;
    $result['remaining_collect'] = max(0, (int) $session['max_collect'] - $linksCount);
}

jsonResponse($result);
