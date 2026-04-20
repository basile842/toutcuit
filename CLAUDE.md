# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Prototype avancé Certify avec backend PHP/MySQL — sessions de classe, soumissions élèves, administration enseignants, CERTs partagées.

**⚠ Site en production, utilisé activement par des enseignants.** Toute modification doit éviter de perturber leur travail : ne pas casser les URLs, l'API, la structure DB ou le comportement en cours de session. Tester soigneusement avant de déployer.

## Stack

- Frontend : HTML/CSS/JS vanilla (ES6 modules, pas de build)
- Backend : PHP 8.2, MySQL, Apache
- Auth : JWT HS256 custom (pas de librairie externe)
- CSS "Obsidian-ish" partagé (`main.css`)

## Conventions

- **Commentaires** en anglais, **contenu/labels UI** en français
- **Écriture épicène** pour les personnes uniquement (élèves, enseignant·es…), pas pour les objets
- `api/config.php` est **gitignored** — contient DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET, JWT_LIFETIME, ANTHROPIC_API_KEY

## Commandes

Pas de build, pas de package manager, pas de tests automatisés. Les seules commandes utiles :

```bash
npx serve .                     # sert le frontend sur :3000 (ou python3 -m http.server 8000)
git push origin main            # ⚠ déclenche le deploy prod FTPS immédiat
```

Le frontend tourne en local mais l'API pointe vers `https://toutcuit.ch/api/` (CORS whiteliste tout localhost en dev). Pour tester `api/ai/review.php` ou autres endpoints en local, il faut un PHP + MySQL local avec son propre `api/config.php`.

Vérification toujours manuelle — tester en local contre l'API de prod avant de pousser.

## Déploiement

- GitHub Actions → FTPS vers Infomaniak (`.github/workflows/deploy.yml`)
- **Push sur `main` = deploy prod immédiat**, site utilisé par des enseignants en séance. Toujours demander confirmation avant `git push` ou `git commit` sur `main`.
- Redirection www → non-www (`.htaccess`), gzip via `.user.ini`

## Routage URL

Le `.htaccess` racine fait deux choses qu'il faut connaître avant de toucher aux pages :
- **Extension `.html` masquée** : `/expert` sert `expert.html`. Toujours référencer les pages sans extension dans les liens.
- **Redirects 301 d'anciens noms** : `/admin → /expert`, `/superadmin → /editor`. Les fichiers `admin.html`, `superadmin.html` et `liens/index.html` existent encore mais ne sont **que des pages de redirection** (titre "Redirection…") — ne pas les confondre avec les vraies pages.

## Pages HTML

| Fichier | URL | Rôle |
|---------|-----|------|
| `index.html` | `/` | Landing (gradient violet, branding) |
| `entrer.html` | `/entrer` | Login enseignant |
| `expert.html` | `/expert` | Dashboard enseignant (sessions, CERTs) — anciennement `admin.html` |
| `session.html` | `/session` | Gestion d'une session individuelle |
| `collector.html` | `/collector` | Formulaire collecte élèves |
| `collected.html` | `/collected` | Vue des liens collectés |
| `global-feed.html` | `/global-feed` | Feed global des réponses |
| `reset.html` | `/reset` | Reset mot de passe |
| `editor.html` | `/editor` | Console éditeur (password-protected, gestion enseignants/sessions/CERTs + outils) — anciennement `superadmin.html` |
| `review.html` | `/review` | Outil de révision éditoriale des CERTs via Claude API |
| `analyse.html` | `/analyse` | Outil d'aide à l'analyse d'une URL pour les expert·es (Claude + Gemini, screenshot optionnel) |

## API (~40 endpoints)

Tous dans `api/`, requêtes JSON, réponses JSON. `api/middleware.php` fournit `handleCors()`, `requireAuth()`, `getTeacherId()`, `jsonResponse()`, `jsonError()`, `getJsonBody()`. `api/db.php` expose `db()` (PDO singleton). `api/.htaccess` bloque `config.php` et tous les `.md`.

Note : `api/generate-cert.php` est à la **racine de `api/`** (et non dans `api/ai/`) pour des raisons historiques. Les trois endpoints AI sont donc répartis entre `api/ai/` (review, compose-cert) et `api/` (generate-cert).

### Auth (`api/auth/`)
- `login.php` [POST] — email + password → JWT token + teacher data (incluant `role`)
- `me.php` [GET] — renvoie le teacher courant (id, email, name, role) depuis le JWT
- `register.php` [POST] — inscription (email unique, password 6+ chars, bcrypt). Par défaut `role = 'expert'`
- `reset-request.php` [POST] — demande de reset par email
- `reset.php` [POST] — reset avec token

### Sessions (`api/sessions/`)
- `create.php` [POST], `list.php` [GET], `update.php` [POST], `delete.php` [DELETE]
- `duplicate.php` [POST] — clone avec nouveau code
- `certs.php` [GET] — CERTs attachées à la session
- `collected.php` [GET] — liens collectés
- `poll.php` [GET] — polling temps réel activité élèves
- `delete-link.php` [DELETE]

### Student (`api/student/`)
- `collect.php` [POST], `submit.php` [POST], `feed.php` [GET], `status.php` [GET], `reset.php` [POST]

### Certs (`api/certs/`)
- `list.php` [GET], `save.php` [POST], `delete.php` [DELETE], `share.php` [POST], `depot.php` [GET], `check-delete.php` [GET]
- **Cycle de review** (voir « Cycle de révision CERT » plus bas) : `request-review.php` [POST] (expert → editor), `complete-review.php` [POST] (editor valide), `return-review.php` [POST] (editor renvoie à l'expert), `reassign-review.php` [POST] (editor redirige vers autre editor), `ack-review.php` [POST] (expert accuse réception de la notification), `my-review-notifications.php` [GET] (notifications non acquittées pour l'expert courant)

### Teachers (`api/teachers/`)
- `editors.php` [GET] — liste publique des enseignant·es `role = 'editor'` (utilisée par l'expert pour choisir à qui envoyer une demande de review)

### Admin (`api/admin/`)
- `teachers.php`, `sessions.php`, `certs.php`, `global-feed.php` [GET] — vues éditeur
- `session-detail.php` [GET] — détail agrégé d'une session (CERTs, compteurs élèves/réponses/liens)
- `update-session.php` [POST], `delete-session.php` [DELETE], `delete-teacher.php` [DELETE], `reset-responses.php` [POST]
- `duplicate-session.php` [POST] — clone côté éditeur (distinct de `sessions/duplicate.php` côté enseignant)
- `set-role.php` [POST] — promouvoir/rétrograder un·e enseignant·e entre `expert` et `editor`
- `pending-reviews.php` [GET] — liste des demandes de review pending/returned/done (vue éditeur globale)
- `activity.php` [GET] — feed filtré de `teacher_activity` + histogramme 42 jours + liste d'actions distinctes. Paramètres : `days` (défaut 7, max 90), `teacher_id`, `action`, `prefix`, `limit` (défaut 500), `before_id` (cursor pour "charger plus").
- `online.php` [GET] — enseignant·es avec `last_seen_at` dans les `minutes` dernières minutes (défaut 5, max 60). Sert le panneau « En ligne maintenant ».

### AI (3 endpoints — `api/ai/` + `api/generate-cert.php`)

Trois outils AI partagent des conventions communes (voir « Parité AI » plus bas) :

- **`api/ai/review.php`** [POST] — Révision éditoriale d'une CERT existante par Claude Sonnet. Si `three_phrases` est vide, demande à Claude de le générer. Sert `review.html`.
- **`api/generate-cert.php`** [POST] — Analyse d'une URL par Claude ou Gemini (multimodal : screenshot optionnel). Récupère le HTML server-side si l'utilisateur ne colle pas le contenu. Sort un JSON structuré `{context, content, visual, references}`. Sert `analyse.html`.
- **`api/ai/compose-cert.php`** [POST] — Prend la sortie de `generate-cert.php` et la transforme en champs CERT prêts à coller (`title`, `three_phrases`, `context`, `content`, `reliability_text`, `references`). Sert le bouton « Générer une CERT » de `analyse.html`.

Prompts système — tous externes, chargés via `file_get_contents()` depuis le même dossier que le PHP qui les utilise. Bloqués en HTTP par `api/.htaccess` (`<FilesMatch "\.md$">`, récursif). **Point d'édition principal** des trois outils : éditer le `.md`, ne jamais réintroduire un heredoc inline.
- **`api/ai/review-prompt.md`** — chargé par `review.php`, avec `strtr()` sur les placeholders `{{METADATA}}`, `{{FIELDS}}`, `{{GENERATE_BLOCK}}` (le seul des trois avec des blocs dynamiques injectés depuis le PHP).
- **`api/generate-cert-prompt.md`** — chargé par `generate-cert.php`. Statique, sans placeholders (URL, contenu récupéré et screenshot transitent par le user message).
- **`api/ai/compose-cert-prompt.md`** — chargé par `compose-cert.php`. Statique, sans placeholders (summary d'analyse et titre de page transitent par le user message).

Clés API dans `config.php` : `ANTHROPIC_API_KEY` (Claude), `GOOGLE_API_KEY` (Gemini).

#### Parité AI

Les trois endpoints AI doivent rester cohérents sur ces trois points :
1. **Retry** : `$maxAttempts = 4`, `$retryableHttp = [429, 503, 529]`, backoff exponentiel ~1s/2s/4s avec jitter. Sur échec final, message `"L'API {Claude|Gemini} est temporairement surchargée (HTTP N) après 4 tentatives. Réessaie dans quelques instants."` (503).
2. **Libellé du bouton de téléchargement RTF** : `Télécharger .rtf` (pas juste `RTF`) dans les trois UI : `review.html`, `analyse.html`, `descripteurs/pages/certs.html`.
3. **Prompt de génération des 3 Phrases** : règles identiques dans `review-prompt.md` et `compose-cert-prompt.md` (format + sujet / fait central / verdict « Fiable / Pas fiable / Indéterminé, car… », pas de références, phrases courtes).

#### Flow analyse → CERT

L'outil `analyse.html` transmet ses résultats à `descripteurs/pages/certs.html` via `localStorage['tc_cert_prefill']` (TTL 10 min, effacé après lecture). **Quand on renomme un champ côté `compose-cert.php`, le répercuter dans deux autres endroits** : le bloc qui pose la clé dans `analyse.html` (`genCert()`), et le bloc qui la lit dans `certs.html` (section « Prefill from analyse tool »). Tout oubli rend le champ silencieusement vide.

### Schools (`api/schools/`)
- `list.php` [GET], `save.php` [POST]

## Authentification

1. POST `/api/auth/login` → JWT token (HS256, lifetime 7 jours)
2. Client envoie `Authorization: Bearer <token>` à chaque requête
3. `middleware.php` : `requireAuth()` valide signature + expiration, `getTeacherId()` extrait l'ID et vérifie l'existence en DB
4. CORS whiteliste `toutcuit.ch` + tout localhost en dev

## Base de données (11 tables)

```
teachers (id, email, password_hash, name, role, last_seen_at, created_at)
  role ENUM('expert','editor') DEFAULT 'expert' — migration 001
  last_seen_at DATETIME NULL                     — migration 003 (présence live)
schools (id, name, created_at)
teacher_school (teacher_id, school_id)           — N:N (⚠ table abandonnée, ne plus maintenir ce lien)
certs (id, teacher_id, teacher_name, title, url, expert, cert_date,
       descriptor1, descriptor2, reliability, three_phrases, context,
       content, reliability_text, references_text, is_shared, created_at)
sessions (id, teacher_id, school_id, name, code, is_open,
          collector_open, max_collect, visible_links, created_at)
session_certs (session_id, cert_id, position)     — N:N
student_responses (id, session_id, user_id, cert_id, first_label,
                   last_label, reliability, comment, dedup_key, created_at)
collected_links (id, session_id, user_id, url, comment, created_at)
password_resets (id, teacher_id, token, expires_at, created_at)
cert_review_requests (id, cert_id, editor_id, requested_by, status,
                      note, editor_comment, requested_at, completed_at,
                      expert_ack_at)                — migration 002
  status ENUM('pending','done','returned')
teacher_activity (id, teacher_id, action, target_type, target_id,
                  meta JSON, created_at)            — migration 003
```

Points clés :
- `dedup_key` empêche les soumissions en double, `is_shared` sur certs permet le partage entre enseignants, pas de table élèves (soumissions anonymes via `user_id` string).
- **`cert_review_requests` = table d'historique** : une ligne par cycle de review. La dernière ligne par `cert_id` (triée par `id` desc) représente l'état courant ; les plus anciennes forment l'historique. Ne jamais écraser une ligne existante — toujours insérer.
- **Migrations SQL** dans `api/migrations/` — à appliquer **manuellement sur la DB de prod via phpMyAdmin Infomaniak** avant de pousser le code qui les consomme. Pas de runner automatique.

## Rôles et cycle de révision CERT

### Rôles `expert` / `editor`

Depuis la migration 001, `teachers.role` vaut soit `expert` (défaut) soit `editor`. Les editors sont peu nombreux·ses (3 initialement, promu·es manuellement via `UPDATE` dans la migration ou via `api/admin/set-role.php` ensuite). Le rôle est transporté dans le JWT et les payloads d'auth ; le front affiche ou masque les zones « demande de review » en conséquence.

### Cycle de révision CERT

Flow métier introduit par la migration 002 (table `cert_review_requests`) et les 7 endpoints de `api/certs/` et `api/admin/pending-reviews.php` :

1. Un·e **expert** ouvre une CERT et clique « Demander une review » → `request-review.php` choisit un·e editor (via `api/teachers/editors.php`) et crée une ligne `status='pending'` avec une `note` (briefing).
2. L'**editor** voit la demande dans son espace. Trois actions possibles :
   - **Valider** (`complete-review.php`) → `status='done'`, `completed_at=NOW()`, `editor_comment` optionnel. Peut être déclenchée via le raccourci « save-and-validate » depuis `review.html`.
   - **Retourner** à l'expert (`return-review.php`) → `status='returned'`, `editor_comment` **obligatoire**.
   - **Rediriger** vers un·e autre editor (`reassign-review.php`) → insère une **nouvelle ligne** `status='pending'` pointant vers le nouvel editor, sans toucher l'ancienne.
3. L'expert reçoit une notification (via `my-review-notifications.php`, polling côté front). Quand il/elle clique pour la lire, le front appelle `ack-review.php` qui remplit `expert_ack_at`. Tant que `expert_ack_at IS NULL`, la notification reste non-lue.
4. `api/admin/pending-reviews.php` agrège l'état global pour la console éditeur.

Points de vigilance :
- **Toujours insérer, jamais update destructif** sur `cert_review_requests`. L'état courant se lit via `SELECT … WHERE cert_id=? ORDER BY id DESC LIMIT 1`.
- Le `editor_comment` est requis pour `returned`, optionnel pour `done`.
- L'export `.rtf` est disponible depuis `review.html`, `depot.html` (du côté éditeur) et `editor.html` — garder le libellé et le contenu cohérents.

## Éditeur et Révision

### Éditeur (`editor.html`)

Console protégée par **vrai login JWT** (email + password d'un compte `role='editor'`, cf. `auth/login.php` + `requireEditor()` dans middleware). Tous les appels passent par `authFetch()` avec `Authorization: Bearer`. Vues :
- **Home** : liens vers outils (CERTs, Analyse, Révision) et onglets gérer (Enseignants, Séances, Stock de CERTs, Activité)
- **Enseignants** : créer, lister, promouvoir/rétrograder, supprimer des comptes
- **Séances** : lister avec détail dépliable (CERTs, stats, feed, dupliquer, télécharger Excel)
- **Stock de CERTs** : lister, modifier, supprimer
- **Activité** : histogramme 6 semaines + panneau « En ligne maintenant » (seuil 5 min, refresh 30 s) + feed d'événements filtrable (teacher / action / plage 1-90 j). Voir `api/admin/activity.php` et `api/admin/online.php`. Alimenté par `logActivity()` dans `middleware.php`, appelé depuis les endpoints de mutation (auth / certs / sessions / review cycle / admin). Les endpoints AI (`ai/review`, `generate-cert`, `ai/compose-cert`) utilisent `optionalTeacherId()` : ils loggent `ai.review` / `ai.generate` / `ai.compose` avec `{model, input_tokens, output_tokens, cost_usd}` uniquement si le frontend a envoyé un Bearer token. Les usages anonymes d'`/analyse` restent autorisés mais ne sont pas tracés.

**Invariant du log** : `teacher_activity.meta` **ne contient jamais de contenu** (titres de CERTs, noms de séances, emails, codes). Seulement des éléments structurels : diffs de rôle, listes de champs modifiés, compteurs, tokens, modèle AI. Les CERTs sont loggées avec quatre verbes distincts — `cert.create` (saisie manuelle), `cert.import` (draft issu d'un upload .docx/.rtf), `cert.update`, `cert.delete` — pour filtrer proprement par type d'action, pas de `cert.save` ambigu.

**Exclusion `auth.login`** : les connexions sont loggées en DB (audit) mais **filtrées côté `api/admin/activity.php`** (feed + histogramme 42 j + dropdown « Action »). La présence live « En ligne maintenant » suffit à savoir qui est connecté — ne pas ré-afficher `auth.login` dans la vue Activité.

Le téléchargement Excel utilise SheetJS côté client, sans endpoint dédié — il charge les données via `sessions/certs.php` + `student/feed.php` et génère le fichier localement.

### Révision (`review.html`)

Outil de révision éditoriale des CERTs via Claude API. Flow :
1. Choisir une CERT → Analyser (appel `api/ai/review.php`)
2. Pour chaque champ modifié, Claude propose une suggestion
3. L'éditeur choisit par champ : **Original** (garder l'existant), **Claude** (accepter la suggestion), ou **Éditer** (modifier manuellement)
4. La colonne "Nouvelle version" reflète le choix en temps réel (couleur + texte)
5. Sauvegarder envoie les modifications à `api/admin/certs.php` (action: update)

Palette UI : violet (accent) pour "Claude", gris pour "Original", ambre pour "Éditer". Le rouge et le vert sont réservés aux indicateurs de fiabilité.

### Analyse (`analyse.html`)

Outil d'aide à l'analyse d'une URL pour les expert·es (accès libre, pas d'auth). Flow :
1. Coller une URL (+ optionnellement un texte copié-collé et/ou un screenshot)
2. Choisir un modèle (Claude Opus/Sonnet/Haiku, Gemini Flash/Pro) → Analyser (appel `api/generate-cert.php`)
3. Le résultat s'affiche en sections éditables : Contexte (concret/thématique), Contenu (claims/style/crosscheck), Visuel (si screenshot), Références numérotées
4. Mode édition inline pour ajuster les bullets, export `.rtf`
5. Bouton « Générer une CERT » → appel `api/ai/compose-cert.php` → ouverture de `descripteurs/pages/certs.html` avec les champs pré-remplis

## Descripteurs

Même structure `descripteurs/` que flanel.ch et kizako.ch. Pages supplémentaires : depot, progress, liens. Intégrés au flow session (les élèves accèdent via code de session, pas en accès libre).
