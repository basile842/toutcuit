<?php
// POST — Teacher registration (editor only — account creation is an admin action)
require_once __DIR__ . '/../middleware.php';
handleCors();
$callerId = requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['email', 'password', 'name']);

$email = strtolower(trim($data['email']));
$name = trim($data['name']);
$password = $data['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Invalid email address');
}

if (strlen($password) < 6) {
    jsonError('Password must be at least 6 characters');
}

$db = getDB();

// Check if email already exists
$stmt = $db->prepare('SELECT id FROM teachers WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    jsonError('Email already registered', 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('INSERT INTO teachers (email, password_hash, name) VALUES (?, ?, ?)');
$stmt->execute([$email, $hash, $name]);

$teacherId = (int) $db->lastInsertId();

$token = jwtCreate([
    'teacher_id' => $teacherId,
    'email'      => $email,
]);

logActivity($callerId, 'teacher.create', 'teacher', $teacherId);

jsonResponse([
    'token'   => $token,
    'teacher' => [
        'id'    => $teacherId,
        'email' => $email,
        'name'  => $name,
    ],
], 201);
