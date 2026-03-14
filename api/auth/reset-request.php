<?php
// POST — Request password reset (sends email with token link)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['email']);

$email = strtolower(trim($data['email']));
$db = getDB();

// Always return success to avoid email enumeration
$successMsg = ['ok' => true, 'message' => 'Si un compte existe avec cet email, un lien de réinitialisation a été envoyé.'];

$stmt = $db->prepare('SELECT id, name FROM teachers WHERE email = ?');
$stmt->execute([$email]);
$teacher = $stmt->fetch();

if (!$teacher) {
    jsonResponse($successMsg);
}

// Generate secure token
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

// Delete any existing tokens for this teacher
$stmt = $db->prepare('DELETE FROM password_resets WHERE teacher_id = ?');
$stmt->execute([$teacher['id']]);

// Store token
$stmt = $db->prepare('INSERT INTO password_resets (teacher_id, token, expires_at) VALUES (?, ?, ?)');
$stmt->execute([$teacher['id'], hash('sha256', $token), $expires]);

// Build reset URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'toutcuit.ch';
$resetUrl = "{$protocol}://{$host}/reset.html?token={$token}";

// Send email
$subject = "toutcuit — Réinitialisation du mot de passe";
$body = "Bonjour {$teacher['name']},\n\n";
$body .= "Vous avez demandé la réinitialisation de votre mot de passe.\n\n";
$body .= "Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe :\n";
$body .= "{$resetUrl}\n\n";
$body .= "Ce lien est valable 1 heure.\n\n";
$body .= "Si vous n'avez pas fait cette demande, ignorez cet email.\n\n";
$body .= "— toutcuit.ch";

$headers = "From: noreply@toutcuit.ch\r\n";
$headers .= "Reply-To: noreply@toutcuit.ch\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

mail($email, $subject, $body, $headers);

jsonResponse($successMsg);
