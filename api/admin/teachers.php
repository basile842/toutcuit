<?php
// GET — List all teachers (for superadmin)
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$db = getDB();

$stmt = $db->query('SELECT id, name, email, created_at FROM teachers ORDER BY created_at DESC');

jsonResponse($stmt->fetchAll());
