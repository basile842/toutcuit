<?php
// POST — Delete a CERT (owner only)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['id']);

$certId = (int) $data['id'];
$db = getDB();

// Verify ownership
$stmt = $db->prepare('SELECT teacher_id, title FROM certs WHERE id = ?');
$stmt->execute([$certId]);
$cert = $stmt->fetch();

if (!$cert || (int) $cert['teacher_id'] !== $teacherId) {
    jsonError('CERT introuvable ou vous n\'en êtes pas l\'auteur-e', 403);
}

// Delete (cascades to session_certs, student_responses)
$stmt = $db->prepare('DELETE FROM certs WHERE id = ?');
$stmt->execute([$certId]);

logActivity($teacherId, 'cert.delete', 'cert', $certId, ['title' => $cert['title']]);

jsonResponse(['deleted' => true, 'id' => $certId]);
