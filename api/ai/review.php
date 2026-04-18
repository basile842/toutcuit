<?php
// POST — Send CERT text fields to Claude for editorial review
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Load Anthropic API key from config
require_once __DIR__ . '/../config.php';
if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    jsonError('ANTHROPIC_API_KEY not configured in api/config.php', 500);
}

$data = getJsonBody();

// Collect only text fields worth reviewing. references_text is included but Claude is
// only allowed to do minimal whitespace/bullet cleanup on it (rules are in review-prompt.md).
$reviewable = ['title', 'three_phrases', 'context', 'content', 'reliability_text', 'references_text'];
$fields = [];
foreach ($reviewable as $key) {
    $val = trim($data[$key] ?? '');
    if ($val !== '') {
        $fields[$key] = $val;
    }
}

// Flag missing three_phrases for auto-generation
$generateThreePhrases = !isset($fields['three_phrases']);

if (empty($fields) && !$generateThreePhrases) {
    jsonError('Aucun champ textuel à réviser.');
}

// Also pass metadata for context
$meta = [
    'url'         => $data['url'] ?? '',
    'expert'      => $data['expert'] ?? '',
    'descriptor1' => $data['descriptor1'] ?? '',
    'descriptor2' => $data['descriptor2'] ?? '',
    'reliability' => $data['reliability'] ?? '',
];

$fieldLabels = [
    'title'           => 'Titre',
    'three_phrases'   => '3 Phrases',
    'context'         => 'Contexte',
    'content'         => 'Contenu',
    'reliability_text'=> 'Fiabilité',
    'references_text' => 'Références',
];

// Build the fields block (rendered into the prompt template)
$fieldsBlock = "";
foreach ($fields as $key => $val) {
    $label = $fieldLabels[$key] ?? $key;
    $fieldsBlock .= "### {$label} (`{$key}`)\n{$val}\n\n";
}

// Build the metadata block
$metadataBlock = "- URL : {$meta['url']}\n"
               . "- Expert : {$meta['expert']}\n"
               . "- Descripteur 1 : {$meta['descriptor1']}\n"
               . "- Descripteur 2 : {$meta['descriptor2']}\n"
               . "- Fiabilité : {$meta['reliability']}";

// Build the optional generate block (only when three_phrases is missing)
$generateBlock = '';
if ($generateThreePhrases) {
    $generateBlock = "\n## Champ manquant à générer\n\n"
                   . "Le champ `three_phrases` (3 Phrases) est VIDE. Tu dois le GÉNÉRER à partir des autres champs disponibles (titre, contexte, contenu, fiabilité, références, métadonnées).\n"
                   . "Inclus-le OBLIGATOIREMENT dans tes suggestions, avec comme \"changes\": [\"Généré automatiquement (le champ était vide)\"].\n"
                   . "Respecte strictement les règles de structure des 3 Phrases définies plus bas.\n";
}

// Load the prompt template from the external markdown file
$promptTemplate = @file_get_contents(__DIR__ . '/review-prompt.md');
if ($promptTemplate === false) {
    jsonError('Could not load review-prompt.md', 500);
}

// Inject the dynamic blocks into the template
$prompt = strtr($promptTemplate, [
    '{{METADATA}}'       => $metadataBlock,
    '{{FIELDS}}'         => rtrim($fieldsBlock),
    '{{GENERATE_BLOCK}}' => $generateBlock,
]);

// Call Claude API
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 4096,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt],
    ],
], JSON_UNESCAPED_UNICODE);

// Retry transient errors (overloaded, rate-limited, gateway issues) with exponential backoff
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
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Success or non-retryable error → stop
    if (!$curlErr && !in_array($httpCode, $retryableHttp, true)) {
        break;
    }
    if ($attempt === $maxAttempts) {
        break;
    }
    // Exponential backoff with jitter: ~1s, 2s, 4s
    $delayMs = (int) ((1 << ($attempt - 1)) * 1000 + random_int(0, 500));
    usleep($delayMs * 1000);
}

if ($curlErr) {
    jsonError("Claude API error: $curlErr", 502);
}

if ($httpCode !== 200) {
    $body = json_decode($response, true);
    $errType = $body['error']['type'] ?? 'unknown';
    $errMsg  = $body['error']['message'] ?? $response;
    // Friendlier message for transient overload after exhausting retries
    if (in_array($httpCode, $retryableHttp, true)) {
        jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode, $errType) après $maxAttempts tentatives. Réessayez dans quelques instants.", 503);
    }
    jsonError("Claude API (HTTP $httpCode, $errType): $errMsg", 502);
}

$result = json_decode($response, true);
$text   = $result['content'][0]['text'] ?? '';

// Parse the JSON from Claude's response
$parsed = json_decode($text, true);
if (!is_array($parsed) || !isset($parsed['suggestions'])) {
    // Try to extract JSON from possible markdown wrapping
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed) || !isset($parsed['suggestions'])) {
        jsonError('Could not parse Claude response', 502);
    }
}

// Attach usage and cost info
$usage = $result['usage'] ?? [];
$inputTokens  = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0);
$outputTokens = $usage['output_tokens'] ?? 0;

// Claude Sonnet 4.6 pricing (USD per token)
$inputCost  = $inputTokens  * 3.0  / 1_000_000;
$outputCost = $outputTokens * 15.0 / 1_000_000;
$totalCost  = $inputCost + $outputCost;

$parsed['usage'] = [
    'input_tokens'  => $inputTokens,
    'output_tokens' => $outputTokens,
    'cost_usd'      => round($totalCost, 4),
];

jsonResponse($parsed);
