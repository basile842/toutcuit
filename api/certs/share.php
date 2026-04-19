<?php
// POST — Share a CERT to the fonds commun
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['cert_id']);

$certId = (int) $data['cert_id'];
$db = getDB();

// Verify ownership
$stmt = $db->prepare('SELECT teacher_id FROM certs WHERE id = ?');
$stmt->execute([$certId]);
$cert = $stmt->fetch();

if (!$cert || (int) $cert['teacher_id'] !== $teacherId) {
    jsonError('CERT not found or not yours', 403);
}

$stmt = $db->prepare('UPDATE certs SET is_shared = 1 WHERE id = ?');
$stmt->execute([$certId]);

logActivity($teacherId, 'cert.share', 'cert', $certId);

jsonResponse(['id' => $certId, 'is_shared' => true]);
