<?php
// Temporary cleanup script — delete after use
require_once __DIR__ . '/db.php';

$db = getDB();
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$db->exec('TRUNCATE TABLE collected_links');
$db->exec('TRUNCATE TABLE student_responses');
$db->exec('TRUNCATE TABLE session_certs');
$db->exec('TRUNCATE TABLE sessions');
$db->exec('TRUNCATE TABLE certs');
$db->exec('TRUNCATE TABLE teacher_school');
$db->exec('TRUNCATE TABLE schools');
$db->exec('TRUNCATE TABLE teachers');
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

jsonResponse(['cleaned' => true]);
