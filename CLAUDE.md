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

## Tests

Pas de suite de tests automatisés. Vérification manuelle uniquement : tester en local contre l'API de prod (CORS autorise localhost) avant de pousser sur `main`, qui déclenche le deploy automatique.

## Développement local

Pas de build. Servir avec un serveur HTTP local (nécessaire pour les ES modules) :

```bash
npx serve .          # ou python3 -m http.server 8000
```

Le frontend tourne en local mais l'API pointe vers `https://toutcuit.ch/api/` (CORS whiteliste tout localhost en dev). Pour tester l'API en local, il faut un PHP + MySQL local avec son propre `api/config.php`.

## Déploiement

- GitHub Actions → FTPS vers Infomaniak (`.github/workflows/deploy.yml`)
- Push sur `main` déclenche le deploy automatique
- Redirection www → non-www (`.htaccess`)
- Gzip activé (`.user.ini`)

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

## API (~37 endpoints)

Tous dans `api/`, requêtes JSON, réponses JSON. `api/middleware.php` fournit `handleCors()`, `requireAuth()`, `getTeacherId()`, `jsonResponse()`, `jsonError()`, `getJsonBody()`. `api/db.php` expose `db()` (PDO singleton). `api/.htaccess` bloque `config.php` et tous les `.md`.

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

### AI (`api/ai/`)
- `review.php` [POST] — envoie les champs texte d'une CERT à Claude Sonnet pour révision éditoriale. Si `three_phrases` est vide, demande à Claude de le générer. Clé API dans `config.php` (`ANTHROPIC_API_KEY`).
- `review-prompt.md` — template du prompt système, chargé par `review.php` via `file_get_contents()` puis `strtr()` sur les placeholders `{{METADATA}}`, `{{FIELDS}}`, `{{GENERATE_BLOCK}}`. **Édite ce fichier pour modifier les règles de révision** sans toucher au PHP. Bloqué en HTTP par `api/.htaccess` (`<FilesMatch "\.md$">`).

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

Console password-protected (pas de JWT, mot de passe frontend) avec vues :
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

## Descripteurs

Même structure `descripteurs/` que flanel.ch et kizako.ch. Pages supplémentaires : depot, progress, liens. Intégrés au flow session (les élèves accèdent via code de session, pas en accès libre).
