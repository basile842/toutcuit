<?php
// Temporary — delete test accounts, then remove this file
require_once __DIR__ . '/db.php';

$db = getDB();

// Delete test teachers (CASCADE handles all related data)
$stmt = $db->prepare('DELETE FROM teachers WHERE email IN (?, ?)');
$stmt->execute(['prof@toutcuit.ch', 'test@toutcuit.ch']);
$deleted = $stmt->rowCount();

// Clean orphan schools
$db->exec('DELETE FROM schools WHERE id NOT IN (SELECT DISTINCT school_id FROM teacher_school)');

jsonResponse(['deleted_teachers' => $deleted, 'ok' => true]);
