<?php
// POST — Editor marks the assigned review as done.
// editor_comment is optional here (the CERT passed validation as-is).
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$editorId = requireEditor();
$data = getJsonBody();
requireFields($data, ['cert_id']);

$certId = (int) $data['cert_id'];
$comment = isset($data['editor_comment']) ? trim((string) $data['editor_comment']) : null;
if ($comment === '') $comment = null;

$db = getDB();

$stmt = $db->prepare("
    SELECT id, editor_id FROM cert_review_requests
    WHERE cert_id = ? AND status = 'pending'
    LIMIT 1
");
$stmt->execute([$certId]);
$req = $stmt->fetch();
if (!$req) {
    jsonError('Aucune demande en cours pour cette CERT', 404);
}
if ((int) $req['editor_id'] !== $editorId) {
    jsonError('Cette demande ne vous est pas assignée', 403);
}

$stmt = $db->prepare("
    UPDATE cert_review_requests
    SET status = 'done', completed_at = NOW(), editor_comment = ?
    WHERE id = ?
");
$stmt->execute([$comment, $req['id']]);

logActivity($editorId, 'review.complete', 'cert', $certId, ['has_comment' => $comment !== null]);

jsonResponse(['id' => (int) $req['id'], 'status' => 'done']);
