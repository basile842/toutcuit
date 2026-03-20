<?php
// POST — Delete a session (superadmin only)
// Removes: session, session_certs, student_responses, collected_links
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['session_id']);

$sessionId = (int) $data['session_id'];
$db = getDB();

// Verify session exists
$stmt = $db->prepare('SELECT id, name, code FROM sessions WHERE id = ?');
$stmt->execute([$sessionId]);
$session = $stmt->fetch();
if (!$session) {
    jsonError('Séance introuvable', 404);
}

$db->beginTransaction();
try {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    $db->prepare('DELETE FROM student_responses WHERE session_id = ?')->execute([$sessionId]);
    $db->prepare('DELETE FROM collected_links WHERE session_id = ?')->execute([$sessionId]);
    $db->prepare('DELETE FROM session_certs WHERE session_id = ?')->execute([$sessionId]);
    $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Clean up schools no longer referenced by any session
    cleanOrphanedSchools($db);

    $db->commit();
} catch (\Exception $e) {
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->rollBack();
    jsonError('Erreur lors de la suppression : ' . $e->getMessage(), 500);
}

jsonResponse([
    'deleted' => true,
    'session_id' => $sessionId,
    'name' => $session['name'],
    'code' => $session['code']
]);
