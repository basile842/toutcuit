<?php
// POST — Change the editor assigned to a pending review.
// The expert who owns the CERT, or the editor currently assigned (redirecting), can reassign.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['cert_id', 'editor_id']);

$certId = (int) $data['cert_id'];
$newEditorId = (int) $data['editor_id'];

$db = getDB();

$stmt = $db->prepare("
    SELECT crr.id, crr.editor_id, c.teacher_id AS cert_owner
    FROM cert_review_requests crr
    JOIN certs c ON c.id = crr.cert_id
    WHERE crr.cert_id = ? AND crr.status = 'pending'
    LIMIT 1
");
$stmt->execute([$certId]);
$req = $stmt->fetch();
if (!$req) {
    jsonError('Aucune demande en cours pour cette CERT', 404);
}

$isOwner = ((int) $req['cert_owner'] === $teacherId);
$isCurrentEditor = ((int) $req['editor_id'] === $teacherId);
if (!$isOwner && !$isCurrentEditor) {
    jsonError('Action non autorisée', 403);
}

$stmt = $db->prepare('SELECT role FROM teachers WHERE id = ?');
$stmt->execute([$newEditorId]);
$editor = $stmt->fetch();
if (!$editor || ($editor['role'] ?? 'expert') !== 'editor') {
    jsonError("Cette personne n'est pas éditeur·ice", 400);
}

if ((int) $req['editor_id'] === $newEditorId) {
    jsonResponse(['id' => (int) $req['id'], 'unchanged' => true]);
}

$stmt = $db->prepare('UPDATE cert_review_requests SET editor_id = ? WHERE id = ?');
$stmt->execute([$newEditorId, $req['id']]);

jsonResponse(['id' => (int) $req['id'], 'editor_id' => $newEditorId]);
