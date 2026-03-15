<?php
// GET  — List all CERTs (superadmin, no auth needed — protected by frontend password)
// POST — Update or delete a CERT (superadmin)
require_once __DIR__ . '/../middleware.php';
handleCors();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query('
        SELECT c.*, t.name AS teacher_name
        FROM certs c
        LEFT JOIN teachers t ON t.id = c.teacher_id
        ORDER BY c.created_at DESC
    ');
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
