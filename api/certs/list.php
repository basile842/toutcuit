<?php
// GET — All CERTs (tagged with is_mine for the authenticated teacher)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$db = getDB();

$stmt = $db->prepare("
    SELECT c.*, COALESCE(c.teacher_name, t.name) AS teacher_name,
           crr.id            AS review_request_id,
           crr.status        AS review_status,
           crr.editor_id     AS review_editor_id,
           editor_t.name     AS review_editor_name,
           crr.note          AS review_expert_note,
           crr.editor_comment AS review_editor_comment,
           crr.requested_at  AS review_requested_at,
           crr.completed_at  AS review_completed_at,
           crr.expert_ack_at AS review_expert_ack_at
    FROM certs c
    LEFT JOIN teachers t ON t.id = c.teacher_id
    LEFT JOIN cert_review_requests crr ON crr.id = (
        SELECT MAX(id) FROM cert_review_requests WHERE cert_id = c.id
    )
    LEFT JOIN teachers editor_t ON editor_t.id = crr.editor_id
    ORDER BY c.created_at DESC
");
$stmt->execute();

$certs = $stmt->fetchAll();

// Tag each cert as "mine" or not
foreach ($certs as &$cert) {
    $cert['is_mine'] = ((int) $cert['teacher_id'] === $teacherId);
}

jsonResponse($certs);
