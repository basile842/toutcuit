<?php
// POST — Analyse assistant: context + content elements for a URL
// Supports Claude (Anthropic) and Gemini (Google) models.
require_once __DIR__ . '/middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();

$url        = trim($data['url'] ?? '');
$context    = trim($data['context'] ?? '');
$content    = trim($data['content'] ?? '');
$model      = trim($data['model'] ?? 'claude-sonnet-4-6');
$screenshot = trim($data['screenshot'] ?? '');

// Whitelist allowed models
$allowedModels = [
    'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001',
    'gemini-2.5-flash', 'gemini-2.5-pro',
];
if (!in_array($model, $allowedModels, true)) {
    $model = 'claude-sonnet-4-6';
}

$isGemini = str_starts_with($model, 'gemini-');

// Validate API key for the selected provider
if ($isGemini) {
    if (!defined('GOOGLE_API_KEY') || GOOGLE_API_KEY === '') {
        jsonError('GOOGLE_API_KEY not configured in api/config.php', 500);
    }
} else {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
        jsonError('ANTHROPIC_API_KEY not configured in api/config.php', 500);
    }
}

if ($url === '') {
    jsonError('Le champ URL est obligatoire.');
}

// Validate screenshot if provided (must be a base64 data URI for an image)
$imageData = null;
$imageMediaType = null;
if ($screenshot !== '') {
    if (preg_match('#^data:(image/(jpeg|png|gif|webp));base64,(.+)$#', $screenshot, $imgMatch)) {
        $imageMediaType = $imgMatch[1];
        $imageData      = $imgMatch[3];
    } else {
        jsonError('Format de screenshot invalide. Utilisez une image PNG, JPEG, GIF ou WebP.');
    }
}

// ── System prompt ─────────────────────────────────────────────────

$systemPrompt = <<<'PROMPT'
Tu es un assistant d'analyse de l'information pour toutcuit.ch (éducation aux médias). Tu aides un-e expert-e à analyser une information trouvée en ligne. Tu fournis des éléments factuels et vérifiables, jamais de conclusion définitive — c'est l'expert-e qui décide.

## Format de sortie

Réponds en JSON valide avec cette structure exacte :

{
  "context": {
    "concrete": [
      "Auteur : Nom (courte description si pertinent) [1]",
      "Date : JJ.MM.AAAA (ou estimation)",
      "Type : article / vidéo / post réseau social / communiqué / etc.",
      "Publication : nom du média ou de la plateforme [2]"
    ],
    "thematic": [
      "Thème : X — sujet largement couvert / peu documenté / très spécialisé",
      "Tendance : sujet viral actuellement / récurrent / ancien",
      "Autre élément thématique pertinent"
    ]
  },
  "content": {
    "claims": [
      "Affirmation 1 — brève reformulation",
      "Affirmation 2 — brève reformulation"
    ],
    "style": [
      "Observation sur le ton, le registre, les procédés rhétoriques",
      "Observation sur la structure, les sources citées ou absentes"
    ],
    "crosscheck_confirmed": [
      "Affirmation X : confirmée par [source] [3]"
    ],
    "crosscheck_contradicted": [
      "Affirmation Y : contredite par [source] [4]"
    ]
  },
  "visual": [
    "Description de ce qui est visible sur le screenshot",
    "Personne reconnue : Nom (fonction) [5]",
    "Logo ou visuel identifié : description"
  ],
  "references": [
    "https://url1.com",
    "https://url2.com"
  ]
}

## Règles

- "claims" : maximum 12 affirmations principales. Prioriser les plus importantes.
- "crosscheck_confirmed" : affirmations confirmées par des sources externes. "crosscheck_contradicted" : affirmations contredites. Si rien trouvé pour une catégorie, tableau vide [].
- Style TÉLÉGRAPHIQUE : bullet points courts, pas de phrases complètes, pas de verbes inutiles.
- Chaque élément doit être concret et vérifiable. Pas de généralités vagues.
- Références : numérotées [1], [2], etc. dans le texte. Les URLs correspondantes dans "references" (même ordre).
- Privilégier sources officielles > institutionnelles > Wikipédia > presse.
- NE PAS conclure sur la fiabilité. Tu fournis les éléments, l'expert-e décide.
- Le champ "visual" n'apparaît QUE si un screenshot est fourni. Si pas de screenshot, mettre un tableau vide [].
- Si un screenshot est fourni : décrire les éléments visuels, tenter d'identifier les personnes (nom, fonction si connue), logos, graphiques, montages éventuels.
- Pas d'émojis. Pas de markdown dans les valeurs (pas de ** ou _).

## Important
- Réponds UNIQUEMENT avec le JSON, sans texte avant ou après.
- Pas de blocs markdown (```json), juste le JSON brut.
PROMPT;

// ── Build user message ─────────────────────────────────────────────

$textMessage = "Analyse cette information :\n\nURL : {$url}";
if ($content !== '') {
    $textMessage .= "\n\nContenu copié-collé depuis la page :\n{$content}";
}
if ($context !== '') {
    $textMessage .= "\n\nNotes de l'expert :\n{$context}";
}
if ($imageData) {
    $textMessage .= "\n\nUn screenshot de la page est joint. Analyse les éléments visuels : texte visible, personnes, logos, mise en page, indices visuels.";
}
if (!$imageData && $content === '') {
    if ($isGemini) {
        $textMessage .= "\n\nUtilise la recherche Google pour accéder au contenu de l'URL et vérifier les informations.";
    } else {
        $textMessage .= "\n\nATTENTION : tu ne peux pas naviguer sur le web. Analyse l'URL (domaine, structure) et utilise tes connaissances. Signale clairement ce que tu ne peux pas vérifier sans accès au contenu.";
    }
}

// ── Call the appropriate API ──────────────────────────────────────

$maxAttempts   = 4;
$retryableHttp = [429, 503, 529];

if ($isGemini) {
    // ── Gemini API ────────────────────────────────────────────────
    $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GOOGLE_API_KEY;

    // Build Gemini parts (multimodal)
    $parts = [];
    if ($imageData) {
        $parts[] = ['inline_data' => ['mime_type' => $imageMediaType, 'data' => $imageData]];
    }
    $parts[] = ['text' => $textMessage];

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => [['parts' => $parts]],
        'generationConfig'   => ['maxOutputTokens' => 4096, 'temperature' => 0.2],
        'tools'              => [['google_search_retrieval' => ['dynamic_retrieval_config' => ['mode' => 'MODE_DYNAMIC', 'dynamic_threshold' => 0.3]]]],
    ], JSON_UNESCAPED_UNICODE);

    $response = null;
    $httpCode = 0;
    $curlErr  = '';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($geminiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && !in_array($httpCode, $retryableHttp, true)) break;
        if ($attempt === $maxAttempts) break;
        usleep((int)((1 << ($attempt - 1)) * 1000 + random_int(0, 500)) * 1000);
    }

    if ($curlErr) jsonError("Gemini API error: $curlErr", 502);

    if ($httpCode !== 200) {
        $body   = json_decode($response, true);
        $errMsg = $body['error']['message'] ?? $response;
        jsonError("Gemini API (HTTP $httpCode): $errMsg", 502);
    }

    $result = json_decode($response, true);

    // With grounding, response may have multiple parts — concatenate text parts
    $text = '';
    foreach (($result['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) $text .= $part['text'];
    }

    // Extract grounding sources (URLs from Google Search)
    $groundingSources = [];
    $grounding = $result['candidates'][0]['groundingMetadata'] ?? [];
    foreach (($grounding['groundingChunks'] ?? []) as $chunk) {
        $uri = $chunk['web']['uri'] ?? '';
        if ($uri !== '' && !in_array($uri, $groundingSources, true)) {
            $groundingSources[] = $uri;
        }
    }

    // Usage
    $usageMeta = $result['usageMetadata'] ?? [];
    $inputTokens  = $usageMeta['promptTokenCount'] ?? 0;
    $outputTokens = $usageMeta['candidatesTokenCount'] ?? 0;

} else {
    // ── Claude API ────────────────────────────────────────────────
    $contentBlocks = [];
    if ($imageData) {
        $contentBlocks[] = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $imageMediaType, 'data' => $imageData],
        ];
    }
    $contentBlocks[] = ['type' => 'text', 'text' => $textMessage];

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 4096,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $contentBlocks]],
    ], JSON_UNESCAPED_UNICODE);

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
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && !in_array($httpCode, $retryableHttp, true)) break;
        if ($attempt === $maxAttempts) break;
        usleep((int)((1 << ($attempt - 1)) * 1000 + random_int(0, 500)) * 1000);
    }

    if ($curlErr) jsonError("Claude API error: $curlErr", 502);

    if ($httpCode !== 200) {
        $body    = json_decode($response, true);
        $errType = $body['error']['type'] ?? 'unknown';
        $errMsg  = $body['error']['message'] ?? $response;
        if (in_array($httpCode, $retryableHttp, true)) {
            jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessaie dans quelques instants.", 503);
        }
        jsonError("Claude API (HTTP $httpCode, $errType): $errMsg", 502);
    }

    $result = json_decode($response, true);
    $text   = $result['content'][0]['text'] ?? '';

    // Usage
    $usage        = $result['usage'] ?? [];
    $inputTokens  = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0);
    $outputTokens = $usage['output_tokens'] ?? 0;
}

// ── Parse response ─────────────────────────────────────────────────

$parsed = json_decode($text, true);
if (!is_array($parsed) || !isset($parsed['context'])) {
    // Try to extract JSON from possible markdown wrapping
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed) || !isset($parsed['context'])) {
        // Debug: show full response structure
        $debug = json_encode($result['candidates'][0] ?? $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        jsonError('Parse error. Response: ' . mb_substr($debug, 0, 1500), 502);
    }
}

// ── Merge grounding sources into references (Gemini only) ─────────

if ($isGemini && !empty($groundingSources)) {
    $existingRefs = $parsed['references'] ?? [];
    foreach ($groundingSources as $src) {
        if (!in_array($src, $existingRefs, true)) {
            $existingRefs[] = $src;
        }
    }
    $parsed['references'] = $existingRefs;
}

// ── Attach usage & cost ────────────────────────────────────────────

$pricing = [
    'claude-opus-4-6'            => ['input' => 15.0,  'output' => 75.0],
    'claude-sonnet-4-6'          => ['input' => 3.0,   'output' => 15.0],
    'claude-haiku-4-5-20251001'  => ['input' => 1.0,   'output' => 5.0],
    'gemini-2.5-flash'           => ['input' => 0.15,  'output' => 0.60],
    'gemini-2.5-pro'             => ['input' => 1.25,  'output' => 10.0],
];
$rates      = $pricing[$model] ?? ['input' => 0, 'output' => 0];
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
