<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function requireAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        jsonError('Missing or invalid Authorization header', 401);
    }

    $payload = jwtValidate($m[1]);
    if (!$payload) {
        jsonError('Invalid or expired token', 401);
    }

    return $payload;
}

function getTeacherId(): int {
    $payload = requireAuth();
    $teacherId = (int) $payload['teacher_id'];

    // Verify teacher still exists in DB
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM teachers WHERE id = ?');
    $stmt->execute([$teacherId]);
    if (!$stmt->fetch()) {
        jsonError('Compte supprimé. Veuillez vous reconnecter.', 401);
    }

    return $teacherId;
}

// Require caller to be an editor. Role is re-read from DB on every call so a
// demoted editor loses access immediately, even if their JWT still says 'editor'.
function requireEditor(): int {
    $teacherId = getTeacherId();
    $db = getDB();
    $stmt = $db->prepare('SELECT role FROM teachers WHERE id = ?');
    $stmt->execute([$teacherId]);
    $row = $stmt->fetch();
    if (!$row || ($row['role'] ?? 'expert') !== 'editor') {
        jsonError('Accès réservé aux éditeur·ices.', 403);
    }
    return $teacherId;
}

// Handle CORS preflight — called by .htaccess or at the top of endpoints
function handleCors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['https://toutcuit.ch', 'https://www.toutcuit.ch', 'http://localhost', 'http://127.0.0.1'];

    // Allow any localhost port for development
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
        header("Access-Control-Allow-Origin: $origin");
    } elseif (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
