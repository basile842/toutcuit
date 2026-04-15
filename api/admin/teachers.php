<?php
// GET — List all teachers (editors only)
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$db = getDB();

$stmt = $db->query('SELECT id, name, email, role, created_at FROM teachers ORDER BY created_at DESC');

jsonResponse($stmt->fetchAll());
