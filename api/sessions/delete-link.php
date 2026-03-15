<?php
// POST — Delete a collected link (session owner only)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['link_id']);

$linkId = (int) $data['link_id'];
$db = getDB();

// Verify teacher owns the session this link belongs to
$stmt = $db->prepare('
    SELECT cl.id FROM collected_links cl
    JOIN sessions s ON s.id = cl.session_id
    WHERE cl.id = ? AND s.teacher_id = ?
');
$stmt->execute([$linkId, $teacherId]);
if (!$stmt->fetch()) {
    jsonError('Lien introuvable', 403);
}

$db->prepare('DELETE FROM collected_links WHERE id = ?')->execute([$linkId]);

jsonResponse(['deleted' => true, 'id' => $linkId]);
