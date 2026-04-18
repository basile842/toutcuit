<?php
// POST — Expert acknowledges the done/returned notification for one of their CERTs.
// Triggered automatically when the expert opens the CERT, or when they dismiss the banner.
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

// Mark unacked done/returned rows for this cert as acked — only if the expert owns the cert.
$stmt = $db->prepare("
    UPDATE cert_review_requests crr
    JOIN certs c ON c.id = crr.cert_id
    SET crr.expert_ack_at = NOW()
    WHERE crr.cert_id = ?
      AND c.teacher_id = ?
      AND crr.status IN ('done','returned')
      AND crr.expert_ack_at IS NULL
");
$stmt->execute([$certId, $teacherId]);

jsonResponse(['acked' => $stmt->rowCount()]);
