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

    // Refresh presence timestamp for the "Activité" tool. Swallow failures so a
    // missing column (migration not yet applied) never breaks the request.
    try {
        $db->prepare('UPDATE teachers SET last_seen_at = NOW() WHERE id = ?')->execute([$teacherId]);
    } catch (Throwable $e) {
        error_log('last_seen_at update failed: ' . $e->getMessage());
    }

    return $teacherId;
}

// Extract the teacher id from the Authorization header if present and valid,
// without erroring if it's missing. Used by endpoints that are PUBLIC but still
// want to attribute the call when the caller happens to be signed in (e.g. the
// AI tools — analyse.html is accessible without login).
function optionalTeacherId(): ?int {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return null;
    $payload = jwtValidate($m[1]);
    if (!$payload || empty($payload['teacher_id'])) return null;
    $teacherId = (int) $payload['teacher_id'];
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM teachers WHERE id = ?');
        $stmt->execute([$teacherId]);
        if (!$stmt->fetch()) return null;
        $db->prepare('UPDATE teachers SET last_seen_at = NOW() WHERE id = ?')->execute([$teacherId]);
    } catch (Throwable $e) {
        error_log('optionalTeacherId failed: ' . $e->getMessage());
        return null;
    }
    return $teacherId;
}

// Append a row to teacher_activity. Logging must never break the caller's
// primary action — any DB error is swallowed to error_log. Call this AFTER the
// main operation has succeeded, with the teacher who performed it.
function logActivity(int $teacherId, string $action, ?string $targetType = null, ?int $targetId = null, ?array $meta = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO teacher_activity (teacher_id, action, target_type, target_id, meta)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $teacherId,
            $action,
            $targetType,
            $targetId,
            $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('logActivity failed: ' . $e->getMessage());
    }
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
