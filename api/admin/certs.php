<?php
// GET  — List all CERTs (superadmin, no auth needed — protected by frontend password)
// POST — Update or delete a CERT (superadmin)
require_once __DIR__ . '/../middleware.php';
handleCors();
requireEditor();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT c.*, COALESCE(c.teacher_name, t.name) AS teacher_name,
               crr.id            AS review_request_id,
               crr.status        AS review_status,
               crr.editor_id     AS review_editor_id,
               editor_t.name     AS review_editor_name,
               crr.note          AS review_expert_note,
               crr.editor_comment AS review_editor_comment,
               crr.requested_at  AS review_requested_at,
               crr.completed_at  AS review_completed_at
        FROM certs c
        LEFT JOIN teachers t ON t.id = c.teacher_id
        LEFT JOIN cert_review_requests crr ON crr.id = (
            SELECT MAX(id) FROM cert_review_requests WHERE cert_id = c.id
        )
        LEFT JOIN teachers editor_t ON editor_t.id = crr.editor_id
        ORDER BY c.created_at DESC
    ");
    jsonResponse($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = getJsonBody();
    requireFields($data, ['action', 'id']);

    $certId = (int) $data['id'];

    // Verify cert exists
    $stmt = $db->prepare('SELECT id FROM certs WHERE id = ?');
    $stmt->execute([$certId]);
    if (!$stmt->fetch()) {
        jsonError('CERT introuvable', 404);
    }

    if ($data['action'] === 'check_delete') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM student_responses WHERE cert_id = ?');
        $stmt->execute([$certId]);
        $count = (int) $stmt->fetchColumn();

        // Sessions currently referencing this CERT (will be silently detached on delete)
        $sessStmt = $db->prepare('
            SELECT s.id, s.name, s.code, t.name AS teacher_name
            FROM session_certs sc
            JOIN sessions s ON s.id = sc.session_id
            LEFT JOIN teachers t ON t.id = s.teacher_id
            WHERE sc.cert_id = ?
            ORDER BY s.created_at DESC
        ');
        $sessStmt->execute([$certId]);
        $sessions = $sessStmt->fetchAll();

        jsonResponse([
            'id' => $certId,
            'student_responses' => $count,
            'sessions' => $sessions,
        ]);
    }

    if ($data['action'] === 'delete') {
        $db->prepare('DELETE FROM certs WHERE id = ?')->execute([$certId]);
        jsonResponse(['deleted' => true, 'id' => $certId]);
    }

    if ($data['action'] === 'update') {
        $allowed = ['title', 'url', 'expert', 'cert_date', 'descriptor1', 'descriptor2',
                     'reliability', 'three_phrases', 'context', 'content',
                     'reliability_text', 'references_text'];

        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = ?";
                $params[] = $data[$col];
            }
        }

        if (!$sets) {
            jsonError('Aucun champ à mettre à jour');
        }

        $params[] = $certId;
        $db->prepare('UPDATE certs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        jsonResponse(['updated' => true, 'id' => $certId]);
    }

    jsonError('Action inconnue');
}

jsonError('Method not allowed', 405);
