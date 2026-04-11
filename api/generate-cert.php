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

$url        = trim($data['url'] ?? '');
$context    = trim($data['context'] ?? '');
$model      = trim($data['model'] ?? 'claude-sonnet-4-6');
$screenshot = trim($data['screenshot'] ?? '');

// Whitelist allowed models
$allowedModels = ['claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'];
if (!in_array($model, $allowedModels, true)) {
    $model = 'claude-sonnet-4-6';
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

// ── System prompt with Certify guidelines ──────────────────────────

$systemPrompt = <<<'PROMPT'
Tu es un assistant expert en analyse de la fiabilité de l'information pour le projet Certify.

Tu dois produire un BROUILLON de certification (CERT) structuré selon le format exact utilisé dans la base de données Certify. Ce brouillon sera ensuite révisé par un-e expert-e humain-e via l'outil de révision.

## Format de sortie

Tu dois répondre en JSON valide avec cette structure exacte :

{
  "reliability": "fiable" | "pas fiable" | "indéterminée",
  "descriptor1": "premier descripteur",
  "descriptor2": "deuxième descripteur (ou chaîne vide)",
  "three_phrases": "Phrase 1 (format + sujet)\nPhrase 2 (affirmation principale)\nPhrase 3 (Fiable/Pas fiable/Indéterminé, car...)",
  "context": "Environ 500 caractères. Type d'information, contexte thématique, source, auteur, crédibilité de la source. Références inline au format (1).",
  "content": "Environ 1000 caractères. Analyse du contenu, arguments, structure, vérifiabilité. Références inline au format (1,2).",
  "reliability_text": "Environ 500 caractères. Évaluation finale avec justification. Commence par 'Fiable, car...' ou 'Pas fiable, car...' ou 'Indéterminé, car...'.",
  "references_text": "https://url1.com\nhttps://url2.com\nhttps://url3.com"
}

## Règles sur les champs

### three_phrases
Strictement 3 phrases, une par ligne (séparées par \n) :
- **Phrase 1** : Format + "à propos de" + sujet. Le sujet DOIT être au début. Ex: "Vidéo YouTube à propos de la découverte d'une nouvelle espèce."
- **Phrase 2** : L'affirmation principale ou le fait central, en une seule phrase.
- **Phrase 3** : Commence TOUJOURS par "Fiable, car..." ou "Pas fiable, car..." ou "Indéterminé, car...". JAMAIS par "Cet article est fiable".
- Les 3 phrases doivent être courtes, compréhensibles de façon autonome.
- JAMAIS de références (1,2) dans les 3 phrases.

### context, content, reliability_text
- Les références sont insérées DANS le texte, au format inline Wikipedia : `Alessandro (1) affirme que XXX`.
- Chaque personne, entité ou concept spécialisé doit avoir une référence.
- Format des numéros : (3,4,5) et PAS (3-5).
- Ne pas redire ce que l'utilisateur voit déjà (plateforme, titre de l'article).
- Mentionner la date seulement si elle est utile à la fiabilité.

### references_text
- Une URL par ligne, texte brut, sans titre ni numérotation.
- L'ordre correspond aux numéros utilisés dans le texte : la première URL = (1), la deuxième = (2), etc.
- Privilégier : sources officielles > institutionnelles > Wikipédia (+ autre source) > articles de journaux.

### reliability_text
- Format strict : "X, car A" (X = jugement, A = raison). Maximum 2 phrases.
- La fiabilité arrive à la FIN, comme conséquence de l'analyse. Ne jamais l'anticiper dans le contexte ou le contenu.
- La déclaration doit pouvoir se lire de manière autonome.

## Règles générales

- Sois clair, synthétique, précis, concis et direct.
- Voix active, pas passive. Remplacer les noms abstraits par des verbes.
- Pas de parenthèses (sauf références), pas de contractions, pas d'émojis.
- Pas d'adjectifs subjectifs. Toute caractérisation factuelle doit être justifiée par une référence ou un exemple concret.
- On certifie l'INFORMATION, pas la source. JAMAIS "fiable car publié par un média de référence".
- Utilise la lecture latérale pour évaluer la fiabilité.
- Vérifie si l'information a déjà été analysée par une organisation de fact-checking.
- Écris le CCF détaillé AVANT les trois phrases (mais présente three_phrases en premier dans le JSON).
- Choisis 1 ou 2 descripteurs parmi : désinformation, satire/parodie, clickbait, opinion, contenu sponsorisé, contenu scientifique, contenu officiel, contenu généré par IA, contenu obsolète, théorie du complot, propagande, manipulation d'image, source anonyme, hors contexte — ou crée des nouveaux si nécessaire.

## Important
- Réponds UNIQUEMENT avec le JSON, sans texte avant ou après.
- Pas de blocs markdown (```json), juste le JSON brut.
- Si tu ne peux pas accéder au contenu de l'URL, indique-le clairement dans l'analyse et base-toi sur ce qui est disponible dans le contexte fourni.
PROMPT;

// ── Build user message ─────────────────────────────────────────────

$textMessage = "Analyse cette information et produis un brouillon de certification :\n\nURL : {$url}";
if ($context !== '') {
    $textMessage .= "\n\nContexte additionnel fourni par l'expert :\n{$context}";
}
if ($imageData) {
    $textMessage .= "\n\nUn screenshot de la page web est joint. Utilise-le pour analyser le contenu visible (titre, texte, images, mise en page, commentaires, etc.).";
} else {
    $textMessage .= "\n\nIMPORTANT : Tu ne peux pas naviguer sur le web. Analyse l'URL elle-même (domaine, structure) et utilise tes connaissances pour produire le meilleur brouillon possible.";
}
$textMessage .= "\n\nL'expert complètera et corrigera ensuite.";

// Build content blocks (multimodal if screenshot is present)
$contentBlocks = [];
if ($imageData) {
    $contentBlocks[] = [
        'type' => 'image',
        'source' => [
            'type'       => 'base64',
            'media_type' => $imageMediaType,
            'data'       => $imageData,
        ],
    ];
}
$contentBlocks[] = ['type' => 'text', 'text' => $textMessage];

// ── Call Claude API with retry logic ───────────────────────────────

$payload = json_encode([
    'model'      => $model,
    'max_tokens' => 4096,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $contentBlocks],
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
        CURLOPT_TIMEOUT        => 120,
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
if (!is_array($parsed) || !isset($parsed['reliability'])) {
    // Try to extract JSON from possible markdown wrapping
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed) || !isset($parsed['reliability'])) {
        jsonError('Impossible de parser la réponse de Claude. Réessaie.', 502);
    }
}

// ── Attach usage & cost ────────────────────────────────────────────

$usage        = $result['usage'] ?? [];
$inputTokens  = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0);
$outputTokens = $usage['output_tokens'] ?? 0;

// Pricing per model (USD per million tokens)
$pricing = [
    'claude-opus-4-6'            => ['input' => 15.0, 'output' => 75.0],
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
