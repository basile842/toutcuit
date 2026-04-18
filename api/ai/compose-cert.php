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
  "three_phrases": "Phrase 1\nPhrase 2\nPhrase 3",
  "context": "…",
  "content": "…",
  "reliability_text": "…",
  "references": "…"
}

## Règles par champ

### title
- Reprendre le **titre original de la source** (tel qu'il apparaît sur la page, copier-coller). Ne pas reformuler, ne pas résumer.
- Si le titre n'est pas déterminable depuis les éléments fournis, chaîne vide "".
- Pas de point final.

### three_phrases
- Exactement 3 phrases, une par ligne (séparateur `\n`).
- **Phrase 1** : Format + « à propos de » + sujet. Le sujet de l'information DOIT être au début. Ex : « Article à propos de la technique chirurgicale OOKP publié sur Sciencepost. » — PAS : « Article publié sur Sciencepost qui relate… ».
- **Phrase 2** : L'affirmation ou le fait central en une seule phrase (pas de liste, pas d'énumération de sources).
- **Phrase 3** : Commence TOUJOURS par « Fiable, car… », « Pas fiable, car… » ou « Indéterminé, car… ». JAMAIS « Cet article est fiable » ou « Le contenu est fiable ».
- Phrases COURTES : 1 verbe = 1 phrase. Compter les verbes.
- JAMAIS de références [1], [2]… dans les 3 phrases.
- JAMAIS d'attribution détaillée de source ou d'auteur dans les 3 phrases (ces infos vont dans le Contexte).
- Le verdict de la phrase 3 doit s'appuyer sur les éléments de crosscheck fournis : confirmations multiples → « Fiable » ; contradictions claires ou absence de preuves → « Pas fiable » ; éléments insuffisants ou contradictoires → « Indéterminé ».

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

### reliability_text
- Reprend la **phrase 3** des 3 Phrases, mais avec le sujet explicite au lieu de la forme courte.
- Patron : « Cet article / Cette vidéo / Ce post / Cette plateforme **est fiable** / **n'est pas fiable** / **a une fiabilité indéterminée**, car… ».
- Même verdict (Fiable / Pas fiable / Indéterminé) que la phrase 3.
- La justification peut être légèrement développée par rapport à la phrase 3, en reprenant un ou deux éléments concrets du `content` (confirmations, contradictions, absence de preuves), mais SANS références [n].
- Une à deux phrases maximum. Pas de markdown, pas d'émojis.

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

## Exemples de sortie bien calibrée

### Exemple 1 — article scientifique (fiable)

{
  "title": "Titre de l'article Sciencepost sur l'OOKP",
  "three_phrases": "Article à propos de la technique chirurgicale de l'ostéo-odonto-kératoprothèse (OOKP) publié sur le magazine Sciencepost.\nL'équipe du professeur Daïen au CHU de Montpellier a fait des progrès avec la technique OOKP même s'il y a des contraintes et des risques associés.\nFiable, car les informations sont confirmées par des publications scientifiques.",
  "context": "Article du magazine de vulgarisation scientifique Sciencepost [1, 2]. L'article présente la technique chirurgicale de l'ostéo-odonto-kératoprothèse (OOKP) [3].",
  "content": "L'article reprend un entretien au professeur Vincent Daïen [4] publié par le quotidien Midi Libre [5] et présente les progrès de la technique OOKP au sein du CHU de Montpellier [6]. Les informations publiées sont correctes et sont confirmées par d'autres sources journalistiques [7, 8, 9] ainsi que par des publications scientifiques [10].",
  "reliability_text": "L'article est fiable, car les informations sont confirmées par des publications scientifiques et reportées aussi par d'autres sources journalistiques.",
  "references": "…"
}

### Exemple 2 — vidéo courte informative (fiable)

{
  "title": "Titre du TikTok Brut sur le TPO",
  "three_phrases": "TikTok à propos de l'interdiction d'utiliser les produits contenant du TPO, qui se trouve dans des durcisseurs pour vernis d'ongle.\nLa vidéo fait le point sur la loi du 01/09/25 qui interdit l'emploi et la mise en commerce des produits qui contiennent du TPO (Trimethylbenzoyl Diphenylphosphine Oxide) en UE, Norvège et Suisse.\nFiable, car les faits sont documentés par des arrêts de loi officiels.",
  "context": "TikTok à propos de l'interdiction d'utiliser les produits contenant du TPO. Le TikTok a été publié par la plateforme d'information Brut [1, 2] le 04/09/2025.",
  "content": "La vidéo fait le point sur l'entrée en vigueur du règlement UE n°2025/977. Cette loi interdit l'emploi et la mise en commerce des produits qui contiennent du TPO (Trimethylbenzoyl Diphenylphosphine Oxide) à partir du 01/09/2025 en UE et Norvège [3]. L'Office fédéral de la sécurité alimentaire et des affaires vétérinaires (OSAV) a imposé la même interdiction en Suisse [4]. Parmi les produits cosmétiques concernés on retrouve les durcisseurs pour vernis d'ongle semi-permanents. La vidéo et les informations sont en accord avec d'autres sources d'information fiables [5-7].",
  "reliability_text": "Le post est fiable, car les faits sont documentés par des arrêts de loi officiels.",
  "references": "…"
}

### Exemple 3 — vidéo humoristique présentée comme complot (pas fiable)

{
  "title": "Le complot — Star Wars",
  "three_phrases": "YouTube à propos de Star Wars et son implication dans un complot imaginaire.\nLa vidéo se moque du complotisme.\nPas fiable, car il s'agit d'une vidéo humoristique et n'apporte pas de preuves.",
  "context": "Vidéo YouTube publiée par le compte officiel de Le Before du Grand Journal [1], une émission TV humoristique diffusée sur la chaîne française Canal+ [2]. La vidéo fait partie d'une série de capsules appelées Le complot [3], diffusée entre 2013 et 2015.",
  "content": "La vidéo avance l'hypothèse que la saga de Star Wars est partie d'un complot organisé par les pays arabes, dont le but n'est pas précisé. Pour faire semblant d'étayer cette thèse, la vidéo propose des coïncidences qui sont fait passer par des preuves : des anagrammes [4], des assonances entre le nom des lieux galactiques et celui de certaines villes du Maroc, des rappels aux coutumes du Maghreb dans des scènes de la saga. La vidéo exploite un biais du cerveau humain : on a du mal à accepter les coïncidences et on essaie de leur donner du sens [5, 6, 7]. D'un point de vue scientifique, ces preuves n'ont pas de valeur, car elles ne sont pas falsifiables [8]. Le but de l'émission est d'amuser les téléspectateurs en utilisant l'humour, pas de les convaincre sur la solidité de la théorie du complot présentée.",
  "reliability_text": "Cette vidéo n'est pas fiable, car elle n'apporte pas de preuves et se base uniquement sur des coïncidences qui ne peuvent être confirmées par aucune source scientifique.",
  "references": "…"
}

Note : dans ces exemples, le champ `references` est abrégé « … » pour la lisibilité. Dans la vraie sortie, respecte scrupuleusement le format numéroté avec tabulation défini plus haut.
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
