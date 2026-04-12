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

// ── System prompt ─────────────────────────────────────────────────

$systemPrompt = <<<'PROMPT'
Tu composes les champs textuels d'une fiche CERT (évaluation de fiabilité d'une information) pour toutcuit.ch. L'expert·e a déjà préparé des éléments d'analyse ; tu transformes ces bullets en paragraphes prêts à coller dans le formulaire CERT.

## Format de sortie

Réponds UNIQUEMENT en JSON valide, sans texte avant/après, sans blocs markdown :

{
  "title": "Titre exact de la source",
  "context": "…",
  "content": "…",
  "references": "…"
}

## Règles par champ

### title
- Reprendre le **titre original de la source** (tel qu'il apparaît sur la page, copier-coller). Ne pas reformuler, ne pas résumer.
- Si le titre n'est pas déterminable depuis les éléments fournis, chaîne vide "".
- Pas de point final.

### context
- 1 à 3 phrases. Identifie la source sans juger.
- Formule canonique : "[Type de contenu] [publié·e / paru·e] sur/par [publisher] [1], à propos de [sujet bref]."
- Exemples de type : "Vidéo YouTube publiée par…", "Article paru sur…", "Post TikTok publié par…", "Parfumerie en ligne proposant…", "Article du quotidien suisse X concernant…".
- Si auteur·e ou date connu·e et pertinent·e, l'inclure avec une référence [2] propre.
- Voix active, phrases courtes.

### content
- 3 à 8 phrases denses, avec références [n] intercalées à chaque affirmation vérifiable.
- Inclure : affirmations principales de la source, observations de style/forme si pertinentes (titre optimisé, images hors contexte, ton, etc.), confirmations/contradictions trouvées lors des recoupements.
- **NE PAS** terminer par une phrase de verdict de fiabilité ("Cet article est fiable, car…"). Cette phrase va dans un autre champ que l'expert·e remplira.
- Tonalité : "Cette vidéo / Cet article / Ce post / Cette plateforme". Utiliser "semble", "peut-être", "demeure" pour signaler l'incertitude quand elle existe.
- Ne pas inventer d'informations absentes des éléments fournis.

### references
- Reprendre les URLs fournies, une par ligne, au format exact avec tabulation :
  `1.\thttps://url1.com `
  `2.\thttps://url2.com `
  (numéro, point, tabulation, URL, espace final)
- Même ordre que les [n] utilisés dans context/content.
- Si aucune URL, chaîne vide "".

## Règles générales

- PAS de markdown (pas de **, pas de _, pas de #).
- PAS d'émojis.
- Pas de verdict ni d'adjectif évaluatif ("excellent", "douteux"…). Tu décris, l'expert·e juge.
- Si un champ ne peut pas être rempli (éléments insuffisants), retourner "".

## Exemple de sortie bien calibrée

Pour une vidéo YouTube de la chaîne Trash listant 10 lieux inaccessibles (références [1] = chaîne Trash, [2] = catalogue "les 10", [3] = article Monde sur Nord-Sentinelle, [4] = article Temps sur Tristan da Cunha, [5] = article Geo sur K2, [6] = article Geo sur canyon Denman, [7] = article Temps sur Challenger Deep) :

{
  "title": "10 LIEUX INACCESSIBLES où vous n'irez JAMAIS",
  "context": "Vidéo YouTube publiée par Trash [1], une chaîne qui publie des vidéos sous la forme de : « les 10 xxx » [2]. Dans cette vidéo, dix lieux sont décrits comme étant inaccessibles pour le commun des mortels.",
  "content": "Dix lieux sont listés, considérés par Trash comme inaccessibles : des îles, dont l'île de Nord-Sentinelle [3] et l'archipel de Tristan da Cunha [4], des montagnes, comme le K2 [5] et des espaces sous-marins, tels que le Canyon du glacier de Denman [6] et le Challenger Deep [7]. Chacun des lieux est situé sur des cartes et les raisons de l'inaccessibilité sont explicitées. Les commentaires sont assortis d'images, dont certaines sont fictives ou hors contexte. Le titre est optimisé pour augmenter le trafic : certains lieux sont effectivement inaccessibles, comme le Canyon du glacier de Denman, mais d'autres le sont moins, comme Challenger Deep ou l'île Tristan da Cunha.",
  "references": "1.\thttps://www.youtube.com/@trash/videos \n2.\thttps://www.youtube.com/@trash/search?query=les%2010 \n3.\thttps://www.lemonde.fr/culture/article/2023/03/22/la-derniere-sentinelle-sur-france-2-une-ile-entre-le-ciel-et-l-enfer_6166580_3246.html \n4.\thttps://www.letemps.ch/societe/sciences-humaines/desoles-lile-plus-reculee-monde \n5.\thttps://www.geo.fr/aventure/alpinisme-k2-le-sommet-de-la-terreur-206026 \n6.\thttps://www.geo.fr/environnement/en-antarctique-des-scientifiques-decouvrent-le-canyon-terrestre-le-plus-profond-au-monde-199087 \n7.\thttps://www.letemps.ch/sciences/abysses-challenger-deep "
}
PROMPT;

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
        'generationConfig'   => ['maxOutputTokens' => 2048, 'temperature' => 0.2],
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
            jsonError("L'API Claude est temporairement surchargée (HTTP $httpCode). Réessaie dans quelques instants.", 503);
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
        jsonError('Impossible de parser la réponse du modèle. Réessaie.', 502);
    }
}

// ── Pricing & response ────────────────────────────────────────────

$pricing = [
    'claude-sonnet-4-6' => ['input' => 3.0,  'output' => 15.0],
    'gemini-2.5-flash'  => ['input' => 0.15, 'output' => 0.60],
];
$rates     = $pricing[$model] ?? ['input' => 0, 'output' => 0];
$totalCost = ($inputTokens * $rates['input'] + $outputTokens * $rates['output']) / 1_000_000;

jsonResponse([
    'title'      => (string)($parsed['title'] ?? ''),
    'context'    => (string)($parsed['context'] ?? ''),
    'content'    => (string)($parsed['content'] ?? ''),
    'references' => (string)($parsed['references'] ?? ''),
    'usage' => [
        'model'         => $model,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_usd'      => round($totalCost, 4),
    ],
]);
