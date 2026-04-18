<?php
// GET — Done/returned review requests not yet acknowledged by the current expert.
// Only the LATEST request per cert is reported (older rows are considered superseded).
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$stmt = $db->prepare("
    SELECT
        crr.id AS request_id,
        crr.cert_id,
        crr.status,
        crr.editor_comment,
        crr.completed_at,
        c.title AS cert_title,
        editor.name AS editor_name
    FROM cert_review_requests crr
    JOIN certs c ON c.id = crr.cert_id
    LEFT JOIN teachers editor ON editor.id = crr.editor_id
    WHERE c.teacher_id = ?
      AND crr.status IN ('done','returned')
      AND crr.expert_ack_at IS NULL
      AND crr.id = (
          SELECT MAX(id) FROM cert_review_requests WHERE cert_id = crr.cert_id
      )
    ORDER BY crr.completed_at DESC
");
$stmt->execute([$teacherId]);

jsonResponse($stmt->fetchAll());
