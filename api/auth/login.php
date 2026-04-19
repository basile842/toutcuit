<?php
// POST — Teacher login → JWT
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['email', 'password']);

$email = strtolower(trim($data['email']));
$password = $data['password'];

$db = getDB();

$stmt = $db->prepare('SELECT id, email, name, password_hash, role FROM teachers WHERE email = ?');
$stmt->execute([$email]);
$teacher = $stmt->fetch();

if (!$teacher || !password_verify($password, $teacher['password_hash'])) {
    jsonError('Invalid email or password', 401);
}

$role = $teacher['role'] ?? 'expert';

$token = jwtCreate([
    'teacher_id' => (int) $teacher['id'],
    'email'      => $teacher['email'],
    'role'       => $role,
]);

// Refresh presence + log the login (getTeacherId is not called on this path)
try {
    $db->prepare('UPDATE teachers SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $teacher['id']]);
} catch (Throwable $e) { error_log('last_seen_at on login failed: ' . $e->getMessage()); }
logActivity((int) $teacher['id'], 'auth.login', null, null, ['role' => $role]);

jsonResponse([
    'token'   => $token,
    'teacher' => [
        'id'    => (int) $teacher['id'],
        'email' => $teacher['email'],
        'name'  => $teacher['name'],
        'role'  => $role,
    ],
]);
