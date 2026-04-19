<?php
// POST — Change a teacher's role (editor only).
// Body: { teacher_id: int, role: 'expert'|'editor' }
require_once __DIR__ . '/../middleware.php';
handleCors();
$callerId = requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['teacher_id', 'role']);

$targetId = (int) $data['teacher_id'];
$role = $data['role'];

if (!in_array($role, ['expert', 'editor'], true)) {
    jsonError('Rôle invalide.', 400);
}

// Safety: an editor cannot demote themselves (avoid locking out the last editor
// by accident). Promotion of self is a no-op anyway.
if ($targetId === $callerId && $role !== 'editor') {
    jsonError('Vous ne pouvez pas retirer votre propre statut d\'éditeur·ice.', 400);
}

$db = getDB();
$stmt = $db->prepare('SELECT id, role, name FROM teachers WHERE id = ?');
$stmt->execute([$targetId]);
$target = $stmt->fetch();
if (!$target) {
    jsonError('Enseignant·e introuvable.', 404);
}

$oldRole = $target['role'] ?? 'expert';
if ($oldRole !== $role) {
    $upd = $db->prepare('UPDATE teachers SET role = ? WHERE id = ?');
    $upd->execute([$role, $targetId]);
    logActivity($callerId, 'teacher.role_change', 'teacher', $targetId, [
        'name'     => $target['name'],
        'old_role' => $oldRole,
        'new_role' => $role,
    ]);
}

jsonResponse(['ok' => true, 'teacher_id' => $targetId, 'role' => $role]);
