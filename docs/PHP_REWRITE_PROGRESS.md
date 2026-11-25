# PHP rewrite progress tracker

See `docs/PHP_REWRITE_PLAN.md` for the full plan and architecture decisions.

This file is the source of truth for what's done. A nightly scheduled agent
resumes this work by reading this checklist, picking the first unchecked
item, implementing it, checking it off, committing, and pushing. If a run
gets interrupted partway through an item, leave it unchecked and the next
run will redo/finish it rather than skipping ahead.

- [ ] 1. `lib/db.php` + MySQL schema (users, sessions, docs, files, login_attempts)
- [ ] 2. `lib/auth.php` (DB-backed sessions, password_hash/verify, rate limiting)
- [ ] 3. `api.php` routing + core CRUD endpoints (`/api/status`, `/api/state`, `/api/docs/{col}/{id}`, `/api/changes`)
- [ ] 4. Auth/admin endpoints (`/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me`, `/api/users`, `/api/test-email`)
- [ ] 5. File upload/download endpoints (`POST /api/files`, `GET /_blob/{id}`)
- [ ] 6. `public_html/index.html`: swap EventSource for polling (`/api/changes?since=`)
- [ ] 7. Cron scripts: `scripts/backup.php`, `scripts/workorder_sync.php`, `scripts/reset_password.php`
- [ ] 8. `config.sample.php`, `.htaccess` files, deployment README section

Each run: commit with a message naming which numbered item(s) it advanced, push to `claude/php-rewrite`, then update this checklist in the same commit.
