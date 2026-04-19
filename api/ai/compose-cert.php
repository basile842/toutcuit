<?php
// POST — Compose CERT fields (title, context, content, references) from analyse output.
// Takes analyse.html data (context/content/references bullets) and asks the model to
// turn it into prose suitable for the CERT form (certs.html). No auth: mirrors
// generate-cert.php, since this is invoked from the public analyse tool.
require_once __DIR__ . '/../middleware.php';
handleCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = getJsonBody();

$url       = trim($data['url'] ?? '');
$analysis  = $data['analysis'] ?? null;
$model     = trim($data['model'] ?? 'claude-sonnet-4-6');

// Only Sonnet and Flash are exposed for CERT composition
$allowedModels = ['claude-sonnet-4-6', 'gemini-2.5-flash'];
if (!in_array($model, $allowedModels, true)) {
    $model = 'claude-sonnet-4-6';
}

if ($url === '' || !is_array($analysis)) {
    jsonError('Champs requis manquants : url, analysis.');
}

$isGemini = str_starts_with($model, 'gemini-');

if ($isGemini) {
    if (!defined('GOOGLE_API_KEY') || GOOGLE_API_KEY === '') {
        jsonError('GOOGLE_API_KEY not configured in api/config.php', 500);
    }
} else {
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
        jsonError('ANTHROPIC_API_KEY not configured in api/config.php', 500);
    }
}

// ── Fetch page <title> so the model can reuse it verbatim ─────────

$pageTitle = '';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ToutcuitBot/1.0)',
    CURLOPT_SSL_VERIFYPEER => true,
]);
$html = curl_exec($ch);
$fetchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($html && $fetchHttpCode >= 200 && $fetchHttpCode < 400) {
    if (preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m)) {
        $pageTitle = html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($pageTitle) > 300) $pageTitle = mb_substr($pageTitle, 0, 300);
    }
}

// ── Build the analyse summary passed to the model ─────────────────

$fmtList = function ($items) {
    if (!is_array($items) || !$items) return '(aucun)';
    $out = '';
    foreach ($items as $it) {
        if (is_string($it) && trim($it) !== '') $out .= "- " . trim($it) . "\n";
    }
    return $out !== '' ? rtrim($out) : '(aucun)';
};

$ctx = $analysis['context'] ?? [];
$cnt = $analysis['content'] ?? [];
$vis = $analysis['visual']  ?? [];
$refs = $analysis['references'] ?? [];

$summary  = "URL : {$url}\n\n";
$summary .= "CONTEXTE — éléments concrets :\n" . $fmtList($ctx['concrete'] ?? []) . "\n\n";
$summary .= "CONTEXTE — éléments thématiques :\n" . $fmtList($ctx['thematic'] ?? []) . "\n\n";
$summary .= "CONTENU — affirmations principales :\n" . $fmtList($cnt['claims'] ?? []) . "\n\n";
$summary .= "CONTENU — style et forme :\n" . $fmtList($cnt['style'] ?? []) . "\n\n";
$summary .= "CONTENU — recoupements :\n" . $fmtList($cnt['crosscheck'] ?? []) . "\n\n";
if (is_array($vis) && $vis) {
    $summary .= "ANALYSE VISUELLE :\n" . $fmtList($vis) . "\n\n";
}
if (is_array($refs) && $refs) {
    $summary .= "RÉFÉRENCES (URLs numérotées [1], [2]… dans le texte) :\n";
    foreach ($refs as $i => $u) {
        $summary .= "[" . ($i + 1) . "] " . $u . "\n";
    }
}

// ── System prompt (external template) ────────────────────────────

$systemPrompt = @file_get_contents(__DIR__ . '/compose-cert-prompt.md');
if ($systemPrompt === false) {
    jsonError('Could not load compose-cert-prompt.md', 500);
}

$userMessage = "Voici les éléments d'analyse à transformer en champs CERT :\n\n" . $summary;
if ($pageTitle !== '') {
    $userMessage .= "\n\nTITRE EXACT DE LA PAGE (à reprendre tel quel dans le champ \"title\") : " . $pageTitle;
}

// ── Call the model ────────────────────────────────────────────────

$maxAttempts   = 4;
$retryableHttp = [429, 503, 529];
$response = null;
$httpCode = 0;
$curlErr  = '';

if ($isGemini) {
    $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GOOGLE_API_KEY;
    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => [['parts' => [['text' => $userMessage]]]],
        'generationConfig'   => [
            'maxOutputTokens'  => 4096,
            'temperature'      => 0.2,
            'responseMimeType' => 'application/json',
            'thinkingConfig'   => ['thinkingBudget' => 0],
        ],
    ], JSON_UNESCAPED_UNICODE);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($geminiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
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
        if (in_array($httpCode, $retryableHttp, true)) {
            jsonError("L'API Gemini est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessayez dans quelques instants.", 503);
        }
        jsonError("Gemini API (HTTP $httpCode): $errMsg", 502);
    }

    $result = json_decode($response, true);
    $text = '';
    foreach (($result['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) $text .= $part['text'];
    }
    $usageMeta    = $result['usageMetadata'] ?? [];
    $inputTokens  = $usageMeta['promptTokenCount'] ?? 0;
    $outputTokens = $usageMeta['candidatesTokenCount'] ?? 0;

} else {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 2048,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ], JSON_UNESCAPED_UNICODE);

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
            jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessayez dans quelques instants.", 503);
        }
        jsonError("Claude API (HTTP $httpCode, $errType): $errMsg", 502);
    }

    $result = json_decode($response, true);
    $text   = $result['content'][0]['text'] ?? '';
    $usage        = $result['usage'] ?? [];
    $inputTokens  = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0);
    $outputTokens = $usage['output_tokens'] ?? 0;
}

// ── Parse response ────────────────────────────────────────────────

$parsed = json_decode($text, true);
if (!is_array($parsed)) {
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        jsonError('Impossible de parser la réponse du modèle. Réessayez.', 502);
    }
}

// ── Pricing & response ────────────────────────────────────────────

$pricing = [
    'claude-sonnet-4-6' => ['input' => 3.0,  'output' => 15.0],
    'gemini-2.5-flash'  => ['input' => 0.15, 'output' => 0.60],
];
$rates     = $pricing[$model] ?? ['input' => 0, 'output' => 0];
$totalCost = ($inputTokens * $rates['input'] + $outputTokens * $rates['output']) / 1_000_000;

$callerId = optionalTeacherId();
if ($callerId) {
    logActivity($callerId, 'ai.compose', null, null, [
        'model'         => $model,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_usd'      => round($totalCost, 4),
    ]);
}

jsonResponse([
    'title'            => (string)($parsed['title'] ?? ''),
    'three_phrases'    => (string)($parsed['three_phrases'] ?? ''),
    'context'          => (string)($parsed['context'] ?? ''),
    'content'          => (string)($parsed['content'] ?? ''),
    'reliability_text' => (string)($parsed['reliability_text'] ?? ''),
    'references'       => (string)($parsed['references'] ?? ''),
    'usage' => [
        'model'         => $model,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_usd'      => round($totalCost, 4),
    ],
]);
