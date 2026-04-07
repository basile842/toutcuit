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

// Collect only text fields worth reviewing
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

// Build the prompt
$fieldsBlock = "";
foreach ($fields as $key => $val) {
    $label = $fieldLabels[$key] ?? $key;
    $fieldsBlock .= "### {$label} (`{$key}`)\n{$val}\n\n";
}

$prompt = <<<'PROMPT'
Tu es un éditeur spécialisé en éducation aux médias numériques pour le projet Certify. On te soumet une CERT (certification de lien web) rédigée par un-e enseignant-e. Ta tâche est de proposer des améliorations éditoriales en suivant des règles précises.

## Métadonnées de la CERT
PROMPT;

$prompt .= "\n- URL : {$meta['url']}\n- Expert : {$meta['expert']}\n- Descripteur 1 : {$meta['descriptor1']}\n- Descripteur 2 : {$meta['descriptor2']}\n- Fiabilité : {$meta['reliability']}\n\n";

$prompt .= "## Champs à réviser\n\n{$fieldsBlock}";

if ($generateThreePhrases) {
    $prompt .= <<<'GEN'

## Champ manquant à générer

Le champ `three_phrases` (3 Phrases) est VIDE. Tu dois le GÉNÉRER à partir des autres champs disponibles (titre, contexte, contenu, fiabilité, références, métadonnées).
Inclus-le OBLIGATOIREMENT dans tes suggestions, avec comme "changes": ["Généré automatiquement (le champ était vide)"].
Respecte strictement les règles de structure des 3 Phrases définies plus haut.

GEN;
}

$prompt .= <<<'RULES'
## Règles éditoriales (à appliquer rigoureusement)

### STRUCTURE DES 3 PHRASES
Les 3 Phrases sont strictement 3 phrases, une par ligne :
- **Phrase 1** : Format + "à propos de" + sujet. Le sujet de l'information DOIT être au début. Ex: "Vidéo YouTube à propos de la découverte d'une nouvelle espèce." PAS "Vidéo publiée sur le site français du magazine National Geographic, qui relate la découverte..."
- **Phrase 2** : L'affirmation principale ou le fait central, en une seule phrase.
- **Phrase 3** : Commence TOUJOURS par "Fiable, car..." ou "Pas fiable, car..." ou "Indéterminé, car..." — JAMAIS par "Cet article est fiable" ou "Le contenu est fiable".
- Les 3 phrases doivent être courtes. Compter les verbes : 1 verbe = 1 phrase.
- JAMAIS de références [1,2,3] dans les 3 phrases.
- JAMAIS d'attributions de source ou de détails sur l'auteur dans les 3 phrases (ces infos vont dans le Contexte).

### CERTIFIER L'INFORMATION, PAS LA SOURCE
- JAMAIS dire "cet article est fiable car il provient d'un média de référence". La notoriété d'une source ne certifie pas automatiquement l'information.
- Toujours vérifier l'information avec des sources EXTERNES : "Fiable, car les informations sont confirmées par des publications scientifiques" ou "Fiable, car les faits sont vérifiés par [source externe]".
- Même pour Wikipédia : le fait qu'un article suit les règles de Wikipédia ne le rend pas fiable — il faut vérifier avec des sources externes.

### CONCISION
- Écrire moins et plus simple. Couper tout ce qui n'est pas nécessaire.
- Supprimer les formes adverbiales en -ment : "probablement", "évidemment", "naturellement", "réellement", "purement", "régulièrement", "habilement", etc.
- Préférer les mots courts aux mots longs.
- L'information essentielle au début de la phrase.
- Penser en termes de 2-3 mots clés, puis 2-3 phrases.

### STYLE ET LANGUE
- Voix active, pas passive : "Paolo mange la pomme" pas "La pomme est mangée par Paolo".
- Remplacer les noms abstraits par des verbes : "Il a décidé" pas "Il a pris la décision".
- Phrases positives : "Il a refusé" pas "Il a décidé de ne pas accepter".
- Sujet et verbe proches l'un de l'autre.
- Utiliser "à propos de" au lieu de "relatant/relate" (trop complexe).
- PAS d'adjectifs subjectifs : jamais "ce bel article", "un avis tranché", "mystérieuses statues".
- PAS de guillemets autour de termes ordinaires. Les guillemets renvoient à un sous-entendu que le lecteur ne partage pas forcément.

### PENSÉE CRITIQUE
- PAS de références au "registre" du discours (registre de vulgarisation, registre tragi-comique, etc.). Le registre est un critère interne, inutile pour déterminer la fiabilité.
- PAS de jugements de valeur ni d'opinions : pas de "Je pense", "On trouve que", "notamment", "comme tout le monde le sait".
- PAS d'affirmations non justifiées.
- PAS de pléonasmes, répétitions, périphrases, redondances.
- PAS de clichés de genre, race, nationalité.
- Vérifier ses propres points de vue : si on inverse la conclusion et que le texte tient toujours, il y a un problème.
- La présence de citations et de références dans une source ne rend pas automatiquement fiable le contenu.

### RÉFÉRENCES
- JAMAIS de références dans les 3 Phrases.
- Chaque nom de personne, d'auteur ou d'acteur doit être accompagné d'une référence.
- Format des numéros : (3,4,5) et PAS (3-5).
- Les références doivent être des liens cliquables, pas des livres physiques. Pour les publications papier, utiliser WorldCat ou Google Books.
- Choix de la source : privilégier sources officielles > sources institutionnelles > Wikipédia (accompagnée d'une autre source) > articles de journaux équilibrés.

### STRUCTURE ARGUMENTATIVE
- Factuel, descriptif, informatif (5W+2H : Qui, quoi, quand, où, pourquoi, comment, combien).
- Structure claire : A → Si B (cause → conséquence).
- Dire ce qu'on veut dire immédiatement.

## Format de réponse

Réponds UNIQUEMENT en JSON valide, sans markdown autour, avec cette structure :
{
  "suggestions": {
    "<field_key>": {
      "suggested": "<texte amélioré complet>",
      "changes": ["<description courte de chaque modification, une par item>"]
    }
  }
}

- N'inclus dans "suggestions" QUE les champs qui ont besoin de modifications.
- Si un champ est déjà bien rédigé, ne l'inclus pas.
- Si aucun champ n'a besoin de modification, réponds : {"suggestions": {}}
- Le champ "suggested" doit contenir le texte COMPLET révisé (pas un diff).
- Le champ "changes" est un tableau de strings décrivant chaque modification apportée (ex: "Supprimé l'adverbe 'réellement'", "Déplacé le sujet en début de phrase", "Remplacé 'relate' par 'à propos de'").
RULES;

// Call Claude API
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 4096,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt],
    ],
], JSON_UNESCAPED_UNICODE);

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

if ($curlErr) {
    jsonError("Claude API error: $curlErr", 502);
}

if ($httpCode !== 200) {
    $body = json_decode($response, true);
    $errType = $body['error']['type'] ?? 'unknown';
    $errMsg  = $body['error']['message'] ?? $response;
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
