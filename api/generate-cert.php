<?php
// POST — Analyse assistant: context + content elements for a URL
// Supports Claude (Anthropic) and Gemini (Google) models.
// Fetches URL content server-side when not provided by the user.
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

// ── Fetch URL content if not provided ─────────────────────────────

$fetchedContent = '';
if ($content === '' && !$imageData && $url !== '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ToutcuitBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    $fetchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html && $fetchHttpCode >= 200 && $fetchHttpCode < 400) {
        // Strip scripts, styles, then tags, then collapse whitespace
        $text = preg_replace('#<script[^>]*>.*?</script>#si', ' ', $html);
        $text = preg_replace('#<style[^>]*>.*?</style>#si', ' ', $text);
        $text = preg_replace('#<nav[^>]*>.*?</nav>#si', ' ', $text);
        $text = preg_replace('#<footer[^>]*>.*?</footer>#si', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Limit to ~8000 chars to avoid blowing up the prompt
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000) . "\n\n[… contenu tronqué]";
        }

        if (mb_strlen($text) > 100) {
            $fetchedContent = $text;
        }
    }
}

// ── System prompt (external template) ────────────────────────────

$systemPrompt = @file_get_contents(__DIR__ . '/generate-cert-prompt.md');
if ($systemPrompt === false) {
    jsonError('Could not load generate-cert-prompt.md', 500);
}

// ── Build user message ─────────────────────────────────────────────

$textMessage = "Analyse cette information :\n\nURL : {$url}";

// Include page content (user-provided or auto-fetched)
$pageContent = $content !== '' ? $content : $fetchedContent;
if ($pageContent !== '') {
    $source = $content !== '' ? 'copié-collé par l\'expert' : 'récupéré automatiquement';
    $textMessage .= "\n\nContenu de la page ({$source}) :\n{$pageContent}";
}

if ($context !== '') {
    $textMessage .= "\n\nNotes de l'expert :\n{$context}";
}
if ($imageData) {
    $textMessage .= "\n\nUn screenshot de la page est joint. Analyse les éléments visuels : texte visible, personnes, logos, mise en page, indices visuels.";
}
if (!$imageData && $pageContent === '') {
    $textMessage .= "\n\nATTENTION : impossible de récupérer le contenu de l'URL. Analyse l'URL (domaine, structure) et utilise tes connaissances. Signale clairement ce que tu ne peux pas vérifier sans accès au contenu.";
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
        'tools'              => [['google_search' => new stdClass()]],
        'generationConfig'   => ['maxOutputTokens' => 4096, 'temperature' => 0.2],
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
        if (in_array($httpCode, $retryableHttp, true)) {
            jsonError("L'API Gemini est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessayez dans quelques instants.", 503);
        }
        jsonError("Gemini API (HTTP $httpCode): $errMsg", 502);
    }

    $result = json_decode($response, true);

    // Concatenate text parts
    $text = '';
    foreach (($result['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) $text .= $part['text'];
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
            jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode) après $maxAttempts tentatives. Réessayez dans quelques instants.", 503);
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
        jsonError('Impossible de parser la réponse. Réessayez.', 502);
    }
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

$parsed['content_fetched'] = !(!$imageData && $pageContent === '');

jsonResponse($parsed);
