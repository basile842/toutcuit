<?php
// GET  — Get CERTs for a session (public, no auth needed — used by students)
// POST — Set CERTs for a session (auth required)
require_once __DIR__ . '/../middleware.php';
handleCors();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public: get CERTs by session code
    $code = $_GET['session'] ?? '';
    if (!$code) {
        jsonError('Missing session code');
    }

    $stmt = $db->prepare('SELECT id, visible_links FROM sessions WHERE code = ?');
    $stmt->execute([$code]);
    $session = $stmt->fetch();
    if (!$session) {
        jsonError('Session not found', 404);
    }

    $showAll = isset($_GET['all']) && $_GET['all'] === '1';
    $visibleLinks = (int) ($session['visible_links'] ?? 0);

    $sql = '
        SELECT c.*, sc.position
        FROM session_certs sc
        JOIN certs c ON c.id = sc.cert_id
        WHERE sc.session_id = ?
        ORDER BY sc.position
    ';
    $params = [$session['id']];

    if (!$showAll && $visibleLinks > 0) {
        $sql .= ' LIMIT ?';
        $params[] = $visibleLinks;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonResponse($stmt->fetchAll());

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auth required: associate CERTs with a session
    $teacherId = getTeacherId();
    $data = getJsonBody();
    requireFields($data, ['session_id', 'certs']);

    $sessionId = (int) $data['session_id'];

    // Verify ownership
    $stmt = $db->prepare('SELECT teacher_id FROM sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session || (int) $session['teacher_id'] !== $teacherId) {
        jsonError('Session not found or not yours', 403);
    }

    // Replace all session_certs
    $db->prepare('DELETE FROM session_certs WHERE session_id = ?')->execute([$sessionId]);

    $stmt = $db->prepare('INSERT INTO session_certs (session_id, cert_id, position) VALUES (?, ?, ?)');
    foreach ($data['certs'] as $i => $entry) {
        $certId = is_array($entry) ? (int) $entry['cert_id'] : (int) $entry;
        $position = is_array($entry) ? (int) ($entry['position'] ?? $i) : $i;
        $stmt->execute([$sessionId, $certId, $position]);
    }

    jsonResponse(['session_id' => $sessionId, 'count' => count($data['certs'])]);

} else {
    jsonError('Method not allowed', 405);
}
