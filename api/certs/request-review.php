<?php
// POST — Expert requests editorial review of one of their CERTs from a specific editor.
// Rejects if a pending request already exists on this cert (the expert must reassign instead).
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
requireFields($data, ['cert_id', 'editor_id']);

$certId = (int) $data['cert_id'];
$editorId = (int) $data['editor_id'];
$note = isset($data['note']) ? trim((string) $data['note']) : null;
if ($note === '') $note = null;

$db = getDB();

$stmt = $db->prepare('SELECT teacher_id FROM certs WHERE id = ?');
$stmt->execute([$certId]);
$cert = $stmt->fetch();
if (!$cert || (int) $cert['teacher_id'] !== $teacherId) {
    jsonError('CERT introuvable ou non autorisée', 403);
}

$stmt = $db->prepare('SELECT role FROM teachers WHERE id = ?');
$stmt->execute([$editorId]);
$editor = $stmt->fetch();
if (!$editor || ($editor['role'] ?? 'expert') !== 'editor') {
    jsonError("Cette personne n'est pas éditeur·ice", 400);
}

$stmt = $db->prepare("
    SELECT id FROM cert_review_requests
    WHERE cert_id = ? AND status = 'pending'
    LIMIT 1
");
$stmt->execute([$certId]);
if ($stmt->fetch()) {
    jsonError('Une demande est déjà en cours pour cette CERT', 409);
}

$stmt = $db->prepare("
    INSERT INTO cert_review_requests (cert_id, editor_id, requested_by, status, note)
    VALUES (?, ?, ?, 'pending', ?)
");
$stmt->execute([$certId, $editorId, $teacherId, $note]);

$requestId = (int) $db->lastInsertId();
logActivity($teacherId, 'review.request', 'cert', $certId, ['editor_id' => $editorId, 'has_note' => $note !== null]);

jsonResponse(['id' => $requestId, 'status' => 'pending'], 201);
