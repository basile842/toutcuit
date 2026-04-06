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
- `api/config.php` est **gitignored** — contient DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET, JWT_LIFETIME

## Déploiement

- GitHub Actions → FTPS vers Infomaniak (`.github/workflows/deploy.yml`)
- Push sur `main` déclenche le deploy automatique
- Redirection www → non-www (`.htaccess`)
- Gzip activé (`.user.ini`)

## Pages HTML

| Fichier | Rôle |
|---------|------|
| `index.html` | Landing (gradient violet, branding) |
| `entrer.html` | Login enseignant |
| `admin.html` | Dashboard enseignant (sessions, CERTs) |
| `session.html` | Gestion d'une session individuelle |
| `collector.html` | Formulaire collecte élèves |
| `collected.html` | Vue des liens collectés |
| `global-feed.html` | Feed global des réponses (admin) |
| `reset.html` | Reset mot de passe |
| `superadmin.html` | Console super-admin |

## API (34 endpoints)

Tous dans `api/`, requêtes JSON, réponses JSON.

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
- `teachers.php`, `sessions.php`, `certs.php`, `global-feed.php` [GET] — vues superadmin
- `update-session.php` [POST], `delete-session.php` [DELETE], `delete-teacher.php` [DELETE], `reset-responses.php` [POST]

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
teacher_school (teacher_id, school_id)           — N:N
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

## Descripteurs

Même structure `descripteurs/` que flanel.ch et kizako.ch. Pages supplémentaires : depot, progress, liens. Intégrés au flow session (les élèves accèdent via code de session, pas en accès libre).
