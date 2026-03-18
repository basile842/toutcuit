<?php
// POST — Check student responses before deleting a CERT (owner only)
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
$stmt = $db->prepare('SELECT teacher_id FROM certs WHERE id = ?');
$stmt->execute([$certId]);
$cert = $stmt->fetch();

if (!$cert || (int) $cert['teacher_id'] !== $teacherId) {
    jsonError('CERT introuvable ou vous n\'en êtes pas l\'auteur-e', 403);
}

$stmt = $db->prepare('SELECT COUNT(*) FROM student_responses WHERE cert_id = ?');
$stmt->execute([$certId]);
$count = (int) $stmt->fetchColumn();

jsonResponse(['id' => $certId, 'student_responses' => $count]);
