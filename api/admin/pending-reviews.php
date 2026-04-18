<?php
// GET — Pending review requests assigned to the current editor.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$editorId = requireEditor();
$db = getDB();

$stmt = $db->prepare("
    SELECT
        crr.id AS request_id,
        crr.cert_id,
        crr.note,
        crr.requested_at,
        crr.requested_by,
        c.title AS cert_title,
        c.reliability,
        c.descriptor1,
        c.descriptor2,
        COALESCE(c.teacher_name, t.name) AS expert_name
    FROM cert_review_requests crr
    JOIN certs c ON c.id = crr.cert_id
    LEFT JOIN teachers t ON t.id = c.teacher_id
    WHERE crr.editor_id = ? AND crr.status = 'pending'
    ORDER BY crr.requested_at ASC
");
$stmt->execute([$editorId]);

jsonResponse($stmt->fetchAll());
