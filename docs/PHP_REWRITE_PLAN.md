# Port Event Board from Node.js/SQLite to PHP/MySQL for exdocreative.com shared hosting

## Context

Event Board (the "Kanban" repo) is currently a Node.js app using `node:sqlite`, designed to run as a persistent process on an always-on machine (per its own README). The user wants to deploy it instead on `exdocreative.com`, a shared cPanel hosting account confirmed to be **plain static/PHP hosting only** — no persistent Node process, no SSH confirmed, FTP + a MySQL database user provided. This forces a full backend rewrite to PHP+MySQL.

Before rewriting, the existing Node implementation was preserved on GitHub as branch `archive/node-event-board` and tag `archive-node-2026-11-24`, so nothing is lost if the user ever moves to Node-capable hosting later.

Confirmed constraints (from the user):
- exdocreative.com is blank/unused — the app can live at the domain root (`public_html/`).
- The hosting account has cPanel **Cron Jobs** available (confirmed) — this replaces Node's `setInterval`-based nightly backup and work-order-sheet-sync timers.
- FTP-only deployment; unknown whether the FTP account can reach above `public_html/`, so the plan assumes it cannot and protects sensitive paths with `.htaccess` instead.
- No local PHP or MySQL interpreter is available in this sandbox (`php -v` / `mysql --version` both fail) — implementation must be done via careful code review and small, auditable pieces rather than local execution, with the user performing the real end-to-end test after FTP upload.
- I will not type the user's real FTP/DB passwords into any tool myself — they perform the actual upload/DB-setup steps; I prepare everything so that's a mechanical copy/paste.

A detailed map of the frontend's networking contract (`public/index.html`) was already produced by an Explore agent and is ground truth for what the PHP backend must satisfy (see "Frontend contract" below) — the frontend needs only one real change (SSE → polling), not a rewrite.

**Separately**, because this is a large port that may span multiple sessions, the user asked for a nightly (midnight) scheduled agent that resumes this work autonomously if a session is cut off by token limits, rather than waiting for the user to notice and say "continue."

## Frontend contract to preserve exactly (public/index.html, ~5% changed)

- `API.req()` wrapper: `x-requested-with: eventboard` header (CSRF defense) on every call, `credentials:'same-origin'`, JSON bodies except file Blob uploads. 401 with `{code:'signed_out'}` forces sign-out client-side.
- `GET /api/status` → `{setup,user,mail}`; `GET /api/state` → `{users,docs:{shows:{},tasks:{},config:{}}}`.
- CRUD: `PUT`/`DELETE /api/docs/{col}/{id}` (`col` ∈ shows|tasks|config), full JSON doc body, ~500ms client debounce.
- Uploads: `POST /api/files` (raw Blob body, `content-type` + `x-filename` headers) → `{id,url,sizeBytes,contentType}`; downloads via auth-gated `GET /_blob/{id}`.
- Auth/admin: `/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me` (PATCH), `/api/users` (POST/PATCH/DELETE, admin-only), `/api/test-email`.
- Doc shapes unchanged: epoch-ms timestamps (not ISO strings), task `rank` is a float, `items`/`files`/`links`/`waiting` default to `[]`.
- **The only required frontend change**: replace the `EventSource('/api/events')` live-update mechanism with polling a new `GET /api/changes?since=<ms>` endpoint every ~3s, returning `{docs:[{col,id,data|null,updated}],users,serverTime}`. Feed each entry into the existing `db.apply(col,id,data)` function exactly as the SSE handler does today — that function doesn't care where updates come from, so no other frontend logic changes. Use `serverTime` (not the max of returned `updated` values) as the next poll's `since` cursor to avoid clock-skew/missed-update races.

## Backend architecture (new)

**Directory layout** (everything under `public_html/`, defensively, since FTP scope above it is unconfirmed):
```
public_html/
  index.html              (near-unchanged; poll loop swapped in for EventSource)
  api.php                 (single front controller for /api/* and /_blob/*)
  .htaccess                (routes /api/* and /_blob/* to api.php; static files served normally)
  config.sample.php        (committed to git; real config.php is NOT committed, created on server)
  lib/
    db.php                 (PDO bootstrap + prepared statements, mirrors server.js's `q` object)
    auth.php               (session tokens, password_hash/verify, rate limiting)
    http.php                (routing table + request/response helpers, JSON body parsing)
  private/                 (.htaccess: Deny from all / require all denied)
    uploads/                (uploaded file blobs; only reachable via /_blob/{id} in api.php)
    backups/                (nightly backup output)
  scripts/                 (.htaccess: Deny from all — these are cron/CLI-only, never web-reachable)
    backup.php              (cron: pure-PHP DB dump + uploads copy, prune to last N)
    workorder_sync.php       (cron: port of workorder-sync.js mapping + apps-script-client.js fetch)
    reset_password.php       (CLI helper, mirrors scripts/reset-password.js)
```
`.htaccess` at the root routes only `/api/*` and `/_blob/*` to `api.php`; everything else (just `index.html`) is served as a normal static file by Apache, matching the current Node behavior of serving the SPA at `/`.

**Database schema** (MySQL/MariaDB, InnoDB, utf8mb4 for emoji-safe text):
```sql
users(id CHAR(32) PK, email VARCHAR(190) UNIQUE, name VARCHAR(80), role VARCHAR(20), pass VARCHAR(255), created BIGINT)
sessions(token_hash CHAR(64) PK, user_id CHAR(32) FK, expires BIGINT)
docs(col VARCHAR(20), id VARCHAR(100), data LONGTEXT, updated BIGINT, updated_by CHAR(32), PRIMARY KEY(col,id), INDEX(updated))
files(id CHAR(32) PK, name VARCHAR(255), type VARCHAR(100), size BIGINT, created BIGINT, user_id CHAR(32))
login_attempts(ip VARCHAR(45) PK, count INT, reset_at BIGINT)   -- new: replaces Node's in-memory rate-limit Map,
                                                                  -- needed because PHP has no state across requests
                                                                  -- on typical shared hosting
```
The `docs.updated` index exists specifically to serve `/api/changes?since=` efficiently.

**Key behavioral changes from the Node version** (all already decided with the user, not open questions):
1. SSE → polling, as above.
2. `password_hash()`/`password_verify()` (PHP native, bcrypt/argon2id) replaces hand-rolled scrypt — fresh deployment, no users to migrate.
3. DB-backed `login_attempts` table replaces the in-memory rate-limit `Map`, since PHP processes don't persist state between requests on shared hosting.
4. Session tokens stay a custom DB-backed scheme (hash stored server-side, raw token in an HttpOnly/SameSite=Lax cookie, ~30 day expiry) rather than switching to native `$_SESSION`, so expiry stays under our control rather than the host's `session.gc_maxlifetime`.
5. Nightly backup and work-order sheet sync move from Node `setInterval` timers to cPanel Cron Jobs invoking the PHP CLI scripts above.
6. Backup uses a **pure-PHP dump** (PDO `SELECT` → generated `INSERT` statements per table, written to a timestamped `.sql` file, plus a recursive copy of `private/uploads/`) rather than shelling out to `mysqldump`, since `exec`/`shell_exec` are frequently disabled on budget shared hosting.
7. Email alerts default to PHP's built-in `mail()` (cPanel wires this up out of the box) instead of configuring SMTP by hand — simpler, and avoids needing any third-party library, consistent with the original app's one-dependency philosophy (it used exactly `nodemailer`; this port uses zero PHP dependencies).

**Work order sheet sync**: `scripts/workorder_sync.php` ports `workorder-sync.js`'s mapping rules 1:1 (status→column table, priority→rank, date parsing including the blank-date placeholder guard, note synthesis, header-mismatch abort) and `apps-script-client.js`'s GET-with-shared-secret-token fetch (via cURL). Merge/orphan-delete logic against MySQL mirrors the already-tested Node logic: sheet-owned fields (title/col/assignee/due/room/notes/doneAt/created) are overwritten on each sync; board-owned fields (items/files/links/waiting) are preserved untouched on update and only defaulted to `[]` on first creation. This is the same design validated earlier by the Node integration test.

## Build order

1. `lib/db.php` + schema — smallest, most foundational, easiest to reason about without execution.
2. `lib/auth.php` (sessions, password hashing, rate limiting) — security-critical, review carefully since it can't be executed locally.
3. `api.php` routing + the straightforward CRUD endpoints (`/api/status`, `/api/state`, `/api/docs/*`, `/api/changes`).
4. Auth/admin endpoints (`/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me`, `/api/users`).
5. File upload/download (`/api/files`, `/_blob/{id}`).
6. `index.html` poll-loop change (smallest frontend diff).
7. `scripts/backup.php`, `scripts/workorder_sync.php`, `scripts/reset_password.php`.
8. `config.sample.php`, `.htaccess` files, and a deployment README section (cPanel cron setup steps, DB import steps, upload-size `.htaccess`/`.user.ini` notes, file permission notes for `private/uploads/`).

Since this can't be executed locally, each piece should be kept small and self-contained enough to review line-by-line for correctness (mirroring the already-verified Node logic wherever a direct analog exists), rather than relying on running it to catch bugs. The user performs the real test after FTP upload.

## Scheduled nightly resume agent (separate from the port itself)

Once this plan is approved, in addition to starting the PHP port, set up a cron-triggered cloud agent (via the `schedule` skill) that runs nightly at midnight and resumes this port autonomously if a prior session was cut off by token limits — checking progress (task list / repo state) and continuing rather than waiting for the user to say "continue."

## Verification

- No local PHP/MySQL execution is available, so verification is primarily careful manual code review plus structural checks (e.g. confirm `.htaccess` rewrite rules, confirm schema matches query usage) rather than running tests.
- Where a Node-side behavior was already verified with an automated check (e.g. the work-order sync's create/update/preserve-board-edits/orphan-delete logic), the PHP port should replicate the same test scenarios as plain comments or a small standalone checklist so the user (or a later session) can manually confirm equivalent behavior after upload.
- Final real-world verification happens after the user uploads via FTP and imports the schema: creating the first admin account, confirming polling-based live updates work across two browser tabs, uploading a file, and running each cron script manually once before relying on the schedule.
