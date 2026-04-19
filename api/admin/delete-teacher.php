<?php
// POST — Delete a teacher account (superadmin only)
// Removes: login, sessions, session_certs, teacher_school, password_resets
// Preserves: certs (depot), student_responses, collected_links
require_once __DIR__ . '/../middleware.php';
handleCors();
$callerId = requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();
requireFields($data, ['teacher_id']);

$teacherId = (int) $data['teacher_id'];
$db = getDB();

// Verify teacher exists
$stmt = $db->prepare('SELECT id, name, email FROM teachers WHERE id = ?');
$stmt->execute([$teacherId]);
$teacher = $stmt->fetch();
if (!$teacher) {
    jsonError('Enseignant-e introuvable', 404);
}

// Get teacher's session IDs
$stmt = $db->prepare('SELECT id FROM sessions WHERE teacher_id = ?');
$stmt->execute([$teacherId]);
$sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$db->beginTransaction();
try {
    // Disable FK checks to preserve orphaned student_responses, collected_links, certs
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    // Delete session_certs for teacher's sessions
    if ($sessionIds) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $db->prepare("DELETE FROM session_certs WHERE session_id IN ($placeholders)")->execute($sessionIds);
    }

    // Delete sessions
    $db->prepare('DELETE FROM sessions WHERE teacher_id = ?')->execute([$teacherId]);

    // Delete teacher_school links
    $db->prepare('DELETE FROM teacher_school WHERE teacher_id = ?')->execute([$teacherId]);

    // Delete password reset tokens
    $db->prepare('DELETE FROM password_resets WHERE teacher_id = ?')->execute([$teacherId]);

    // Delete teacher login
    $db->prepare('DELETE FROM teachers WHERE id = ?')->execute([$teacherId]);

    // Re-enable FK checks
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Clean up schools no longer referenced by any session
    cleanOrphanedSchools($db);

    $db->commit();
} catch (\Exception $e) {
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->rollBack();
    jsonError('Erreur lors de la suppression : ' . $e->getMessage(), 500);
}

logActivity($callerId, 'teacher.delete', 'teacher', $teacherId, [
    'sessions_deleted' => count($sessionIds),
]);

jsonResponse([
    'deleted' => true,
    'teacher_id' => $teacherId,
    'sessions_deleted' => count($sessionIds)
]);
