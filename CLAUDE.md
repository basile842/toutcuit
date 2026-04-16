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
- `login.php` [POST] — email + password → JWT token + teacher data
- `register.php` [POST] — inscription (email unique, password 6+ chars, bcrypt)
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

### Admin (`api/admin/`)
- `teachers.php`, `sessions.php`, `certs.php`, `global-feed.php` [GET] — vues éditeur
- `session-detail.php` [GET] — détail agrégé d'une session (CERTs, compteurs élèves/réponses/liens)
- `update-session.php` [POST], `delete-session.php` [DELETE], `delete-teacher.php` [DELETE], `reset-responses.php` [POST]
- `duplicate-session.php` [POST] — clone côté éditeur (distinct de `sessions/duplicate.php` côté enseignant)

### AI (3 endpoints — `api/ai/` + `api/generate-cert.php`)

Trois outils AI partagent des conventions communes (voir « Parité AI » plus bas) :

- **`api/ai/review.php`** [POST] — Révision éditoriale d'une CERT existante par Claude Sonnet. Si `three_phrases` est vide, demande à Claude de le générer. Sert `review.html`.
- **`api/generate-cert.php`** [POST] — Analyse d'une URL par Claude ou Gemini (multimodal : screenshot optionnel). Récupère le HTML server-side si l'utilisateur ne colle pas le contenu. Sort un JSON structuré `{context, content, visual, references}`. Sert `analyse.html`.
- **`api/ai/compose-cert.php`** [POST] — Prend la sortie de `generate-cert.php` et la transforme en champs CERT prêts à coller (`title`, `three_phrases`, `context`, `content`, `reliability_text`, `references`). Sert le bouton « Générer une CERT » de `analyse.html`.

Prompts système :
- **`api/ai/review-prompt.md`** — template externe chargé par `review.php` via `file_get_contents()` + `strtr()` sur `{{METADATA}}`, `{{FIELDS}}`, `{{GENERATE_BLOCK}}`. **Point d'édition principal** pour `review.php`. Bloqué en HTTP par `api/.htaccess` (`<FilesMatch "\.md$">`).
- Les prompts de `generate-cert.php` et `compose-cert.php` sont **inline** dans le PHP (heredoc `<<<'PROMPT'`). Incohérence à garder en tête — les éditer directement dans le fichier PHP.

Clés API dans `config.php` : `ANTHROPIC_API_KEY` (Claude), `GOOGLE_API_KEY` (Gemini).

#### Parité AI

Les trois endpoints AI doivent rester cohérents sur ces trois points :
1. **Retry** : `$maxAttempts = 4`, `$retryableHttp = [429, 503, 529]`, backoff exponentiel ~1s/2s/4s avec jitter. Sur échec final, message `"L'API {Claude|Gemini} est temporairement surchargée (HTTP N) après 4 tentatives. Réessaie dans quelques instants."` (503).
2. **Libellé du bouton de téléchargement RTF** : `Télécharger .rtf` (pas juste `RTF`) dans les trois UI : `review.html`, `analyse.html`, `descripteurs/pages/certs.html`.
3. **Prompt de génération des 3 Phrases** : règles identiques dans `review-prompt.md` et dans le heredoc de `compose-cert.php` (format + sujet / fait central / verdict « Fiable / Pas fiable / Indéterminé, car… », pas de références, phrases courtes).

#### Flow analyse → CERT

L'outil `analyse.html` transmet ses résultats à `descripteurs/pages/certs.html` via `localStorage['tc_cert_prefill']` (TTL 10 min, effacé après lecture). **Quand on renomme un champ côté `compose-cert.php`, le répercuter dans deux autres endroits** : le bloc qui pose la clé dans `analyse.html` (`genCert()`), et le bloc qui la lit dans `certs.html` (section « Prefill from analyse tool »). Tout oubli rend le champ silencieusement vide.

### Schools (`api/schools/`)
- `list.php` [GET], `save.php` [POST]

## Authentification

1. POST `/api/auth/login` → JWT token (HS256, lifetime 7 jours)
2. Client envoie `Authorization: Bearer <token>` à chaque requête
3. `middleware.php` : `requireAuth()` valide signature + expiration, `getTeacherId()` extrait l'ID et vérifie l'existence en DB
4. CORS whiteliste `toutcuit.ch` + tout localhost en dev

## Base de données (9 tables)

```
teachers (id, email, password_hash, name, created_at)
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
```

Points clés : `dedup_key` empêche les soumissions en double, `is_shared` sur certs permet le partage entre enseignants, pas de table élèves (soumissions anonymes via `user_id` string).

## Éditeur et Révision

### Éditeur (`editor.html`)

Console password-protected (pas de JWT, mot de passe hardcodé frontend dans `editor.html` — constante `PASSWORD`) avec vues :
- **Home** : liens vers Enseignants, Séances, Stock de CERTs, et outils (Révision)
- **Enseignants** : créer, lister, supprimer des comptes
- **Séances** : lister avec détail dépliable (CERTs, stats, feed, dupliquer, télécharger Excel)
- **Stock de CERTs** : lister, modifier, supprimer

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
