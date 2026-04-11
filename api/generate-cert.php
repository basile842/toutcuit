<?php
// POST — Generate a draft CERT from a URL + optional context
// Uses the same Anthropic API key as the editorial review tool.
require_once __DIR__ . '/middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Load Anthropic API key from shared config (already loaded by db.php via middleware.php)
if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    jsonError('ANTHROPIC_API_KEY not configured in api/config.php', 500);
}

$data = getJsonBody();

$url     = trim($data['url'] ?? '');
$context = trim($data['context'] ?? '');
$model   = trim($data['model'] ?? 'claude-sonnet-4-6');

// Whitelist allowed models
$allowedModels = ['claude-sonnet-4-6', 'claude-haiku-4-5-20251001'];
if (!in_array($model, $allowedModels, true)) {
    $model = 'claude-sonnet-4-6';
}

if ($url === '') {
    jsonError('Le champ URL est obligatoire.');
}

// ── System prompt with Certify guidelines ──────────────────────────

$systemPrompt = <<<'PROMPT'
Tu es un assistant expert en analyse de la fiabilité de l'information pour le projet Certify.

Tu dois produire un BROUILLON de certification (CERT) structuré selon les guidelines Certify. Ce brouillon sera ensuite révisé par un-e expert-e humain-e.

## Format de sortie

Tu dois répondre en JSON valide avec cette structure exacte :

{
  "etiquette": "fiable" | "pas fiable" | "indéterminée",
  "descripteurs": ["descripteur1", "descripteur2"],
  "trois_phrases": {
    "contexte": "Une phrase sur le contexte et la source",
    "contenu": "Une phrase sur le contenu et les arguments principaux",
    "fiabilite": "Une phrase d'évaluation avec 'parce que'"
  },
  "ccf": {
    "contexte": "Environ 500 caractères. Type d'information, contexte thématique, source, auteur, date, crédibilité de la source.",
    "contenu": "Environ 1000 caractères. Analyse du contenu, arguments, structure, style, vérifiabilité, données, images/vidéos le cas échéant.",
    "fiabilite": "Environ 500 caractères. Évaluation finale avec justification utilisant 'parce que'."
  },
  "references": [
    {"titre": "Titre de la référence", "url": "https://..."}
  ]
}

## Règles

- Sois clair, synthétique, précis, concis et direct.
- Pas de contractions, pas d'émojis.
- Utilise la lecture latérale pour évaluer la fiabilité.
- Pour chaque argument, utilise des références sous forme de liens.
- Écris les trois phrases APRÈS avoir rédigé le CCF détaillé (mais présente-les en premier dans le JSON).
- Les trois phrases doivent être cohérentes et compréhensibles de façon autonome.
- Dans la phrase de fiabilité, utilise le mot "parce que".
- Vérifie si l'information a déjà été analysée par une organisation de fact-checking.
- Choisis 1 ou 2 descripteurs parmi : désinformation, satire/parodie, clickbait, opinion, contenu sponsorisé, contenu scientifique, contenu officiel, contenu généré par IA, contenu obsolète, théorie du complot, propagande, manipulation d'image, source anonyme, hors contexte — ou crée des nouveaux si nécessaire.

## Important
- Réponds UNIQUEMENT avec le JSON, sans texte avant ou après.
- Pas de blocs markdown (```json), juste le JSON brut.
- Si tu ne peux pas accéder au contenu de l'URL, indique-le clairement dans l'analyse et base-toi sur ce qui est disponible dans le contexte fourni.
PROMPT;

// ── Build user message ─────────────────────────────────────────────

$userMessage = "Analyse cette information et produis un brouillon de certification :\n\nURL : {$url}";
if ($context !== '') {
    $userMessage .= "\n\nContexte additionnel fourni par l'expert :\n{$context}";
}
$userMessage .= "\n\nIMPORTANT : Tu ne peux pas naviguer sur le web. Analyse l'URL elle-même (domaine, structure) et utilise tes connaissances pour produire le meilleur brouillon possible. L'expert complètera et corrigera ensuite.";

// ── Call Claude API with retry logic ───────────────────────────────

$payload = json_encode([
    'model'      => $model,
    'max_tokens' => 4096,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $userMessage],
    ],
], JSON_UNESCAPED_UNICODE);

$maxAttempts   = 4;
$retryableHttp = [429, 503, 529];
$response = null;
$httpCode = 0;
$curlErr  = '';

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if (!$curlErr && !in_array($httpCode, $retryableHttp, true)) {
        break;
    }
    if ($attempt === $maxAttempts) {
        break;
    }
    $delayMs = (int) ((1 << ($attempt - 1)) * 1000 + random_int(0, 500));
    usleep($delayMs * 1000);
}

if ($curlErr) {
    jsonError("Claude API error: $curlErr", 502);
}

if ($httpCode !== 200) {
    $body    = json_decode($response, true);
    $errType = $body['error']['type'] ?? 'unknown';
    $errMsg  = $body['error']['message'] ?? $response;
    if (in_array($httpCode, $retryableHttp, true)) {
        jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessaie dans quelques instants.", 503);
    }
    jsonError("Claude API (HTTP $httpCode, $errType): $errMsg", 502);
}

// ── Parse response ─────────────────────────────────────────────────

$result = json_decode($response, true);
$text   = $result['content'][0]['text'] ?? '';

$parsed = json_decode($text, true);
if (!is_array($parsed) || !isset($parsed['etiquette'])) {
    // Try to extract JSON from possible markdown wrapping
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed) || !isset($parsed['etiquette'])) {
        jsonError('Impossible de parser la réponse de Claude. Réessaie.', 502);
    }
}

// ── Attach usage & cost ────────────────────────────────────────────

$usage        = $result['usage'] ?? [];
$inputTokens  = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0);
$outputTokens = $usage['output_tokens'] ?? 0;

// Pricing per model (USD per token)
$pricing = [
    'claude-sonnet-4-6'          => ['input' => 3.0, 'output' => 15.0],
    'claude-haiku-4-5-20251001'  => ['input' => 1.0, 'output' => 5.0],
];
$rates      = $pricing[$model] ?? $pricing['claude-sonnet-4-6'];
$inputCost  = $inputTokens  * $rates['input']  / 1_000_000;
$outputCost = $outputTokens * $rates['output'] / 1_000_000;
$totalCost  = $inputCost + $outputCost;

$parsed['usage'] = [
    'model'         => $model,
    'input_tokens'  => $inputTokens,
    'output_tokens' => $outputTokens,
    'cost_usd'      => round($totalCost, 4),
];

jsonResponse($parsed);
