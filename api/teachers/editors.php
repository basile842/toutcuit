<?php
// GET — List of teachers with role='editor'. Used by experts to pick an editor when requesting a review.
// Accessible to any authenticated teacher (not just editors) since experts need to pick from it.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

getTeacherId();

$db = getDB();
$stmt = $db->query("SELECT id, name FROM teachers WHERE role = 'editor' ORDER BY name");

jsonResponse($stmt->fetchAll());
