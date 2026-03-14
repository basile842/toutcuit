<?php
// POST — Reset password using token
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['token', 'password']);

$token = $data['token'];
$password = $data['password'];

if (strlen($password) < 6) {
    jsonError('Le mot de passe doit contenir au moins 6 caractères.');
}

$db = getDB();
$tokenHash = hash('sha256', $token);

// Find valid token
$stmt = $db->prepare('
    SELECT pr.teacher_id, pr.expires_at
    FROM password_resets pr
    WHERE pr.token = ?
');
$stmt->execute([$tokenHash]);
$reset = $stmt->fetch();

if (!$reset) {
    jsonError('Lien invalide ou expiré.', 400);
}

if (strtotime($reset['expires_at']) < time()) {
    // Clean up expired token
    $stmt = $db->prepare('DELETE FROM password_resets WHERE token = ?');
    $stmt->execute([$tokenHash]);
    jsonError('Ce lien a expiré. Veuillez refaire une demande.', 400);
}

// Update password
$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare('UPDATE teachers SET password_hash = ? WHERE id = ?');
$stmt->execute([$hash, $reset['teacher_id']]);

// Delete used token
$stmt = $db->prepare('DELETE FROM password_resets WHERE teacher_id = ?');
$stmt->execute([$reset['teacher_id']]);

jsonResponse(['ok' => true, 'message' => 'Mot de passe mis à jour avec succès.']);
