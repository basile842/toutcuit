<?php
// GET — Current authenticated teacher (id, email, name, role).
// Used by /expert and /editor to refresh role from DB on page load,
// so promotions/demotions take effect without forcing re-login.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$teacherId = getTeacherId();

$db = getDB();
$stmt = $db->prepare('SELECT id, email, name, role FROM teachers WHERE id = ?');
$stmt->execute([$teacherId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    jsonError('Compte introuvable.', 401);
}

jsonResponse([
    'id'    => (int) $teacher['id'],
    'email' => $teacher['email'],
    'name'  => $teacher['name'],
    'role'  => $teacher['role'] ?? 'expert',
]);
