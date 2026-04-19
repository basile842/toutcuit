<?php
// POST — Create or update a CERT
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();
$data = getJsonBody();
$db = getDB();

requireFields($data, ['title', 'url', 'reliability']);

// Validate reliability
$allowed = ['good', 'mid', 'bad'];
if (!in_array($data['reliability'], $allowed, true)) {
    jsonError('reliability must be one of: good, mid, bad');
}

$fields = [
    'title'           => trim($data['title']),
    'url'             => trim($data['url']),
    'expert'          => $data['expert'] ?? null,
    'cert_date'       => $data['cert_date'] ?? null,
    'descriptor1'     => $data['descriptor1'] ?? null,
    'descriptor2'     => $data['descriptor2'] ?? null,
    'reliability'     => $data['reliability'],
    'three_phrases'   => $data['three_phrases'] ?? null,
    'context'         => $data['context'] ?? null,
    'content'         => $data['content'] ?? null,
    'reliability_text'=> $data['reliability_text'] ?? null,
    'references_text' => $data['references_text'] ?? null,
];

// Update existing CERT
if (!empty($data['id'])) {
    $certId = (int) $data['id'];

    // Verify ownership
    $stmt = $db->prepare('SELECT teacher_id FROM certs WHERE id = ?');
    $stmt->execute([$certId]);
    $cert = $stmt->fetch();
    if (!$cert || (int) $cert['teacher_id'] !== $teacherId) {
        jsonError('CERT not found or not yours', 403);
    }

    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $sets[] = "$col = ?";
        $params[] = $val;
    }
    $params[] = $certId;

    $stmt = $db->prepare('UPDATE certs SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    logActivity($teacherId, 'cert.update', 'cert', $certId);

    jsonResponse(['id' => $certId, 'updated' => true]);
}

// Create new CERT — store teacher name so it survives account deletion
$fields['teacher_id'] = $teacherId;

$stmt = $db->prepare('SELECT name FROM teachers WHERE id = ?');
$stmt->execute([$teacherId]);
$teacher = $stmt->fetch();
$fields['teacher_name'] = $teacher ? $teacher['name'] : null;

$cols = array_keys($fields);
$placeholders = array_fill(0, count($cols), '?');

$stmt = $db->prepare(
    'INSERT INTO certs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
);
$stmt->execute(array_values($fields));

$newCertId = (int) $db->lastInsertId();
logActivity($teacherId, 'cert.create', 'cert', $newCertId);

jsonResponse(['id' => $newCertId, 'created' => true], 201);
