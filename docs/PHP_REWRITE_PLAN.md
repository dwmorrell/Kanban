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
- **The only required frontend change**: replace the `EventSource('/api/events')` live-update mechanism with polling a new `GET /api/changes?since=<cursor>` endpoint every ~3s, returning `{docs:[{col,id,data|null,updated}],users,cursor,serverTime}`. Feed each entry into the existing `db.apply(col,id,data)` function exactly as the SSE handler does today — that function doesn't care where updates come from, so no other frontend logic changes. **Use the `cursor` field (not `serverTime`, and not the max of returned `updated` values) as the next poll's `since`** — see "Polling cursor" below for why `updated` alone is unsafe. Seed the very first poll's cursor from `/api/state`'s own `cursor` field, not `0`, so the initial poll doesn't redeliver the whole board's history (harmless since `db.apply` is idempotent, just wasteful).

## Backend architecture (new)

**Directory layout** (everything under `public_html/`, defensively, since FTP scope above it is unconfirmed). This is what's actually been built so far (as of item 3) plus what's still to come:
```
public_html/
  index.html              (near-unchanged; poll loop swapped in for EventSource — item 6)
  api.php                 (single front controller for /api/* and /_blob/* — built, item 3)
  .htaccess                (routes /api/* and /_blob/* to api.php; static files served normally — item 8)
  config.php               (NOT committed; real DB creds etc., created directly on the server)
  config.sample.php        (committed; placeholder template — item 8)
  lib/
    db.php                 (PDO + Q data-access class, built — item 1)
    auth.php               (sessions, password hashing, rate limiting, built — item 2)
    http.php                (Router/run_api, JSON body helpers, built — item 3)
    workorder_mapping.php    (pure port of workorder-sync.js's mapping rules — item 7)
    apps_script_client.php   (cURL port of apps-script-client.js — item 7)
  private/                 (.htaccess: Deny from all / Require all denied, both forms for Apache 2.2/2.4)
    uploads/                (uploaded file blobs; only reachable via /_blob/{id} in api.php — item 5)
    backups/                (nightly backup output — item 7)
  cron/                    (.htaccess: Deny from all — CLI-only, cPanel Cron Jobs invoke these by path)
    backup.php              (pure-PDO DB dump + uploads copy, prune to last N — item 7)
    workorder_sync.php       (polls the Apps Script Web App, upserts/deletes task cards — item 7)
    reset_password.php       (CLI helper, mirrors scripts/reset-password.js — item 7)
```
`.htaccess` at the root routes only `/api/*` and `/_blob/*` to `api.php`; everything else (just `index.html`) is served as a normal static file by Apache, matching the current Node behavior of serving the SPA at `/`. `cron/` lives under `public_html/` (not a sibling directory) because the FTP account's reach above `public_html/` is unconfirmed; it's protected the same defensive way as `private/`.

**Database schema** (MySQL/MariaDB, InnoDB, utf8mb4 for emoji-safe text — as built in `lib/db.php`'s `ensure_schema()`):
```sql
users(id CHAR(32) PK, email VARCHAR(190) UNIQUE, name VARCHAR(80), role VARCHAR(20), pass VARCHAR(255), created BIGINT)
sessions(token_hash CHAR(64) PK, user_id CHAR(32) FK, expires BIGINT)
doc_seq(seq BIGINT UNSIGNED AUTO_INCREMENT PK)   -- ticker table, see "Polling cursor" below; not queried directly
                                                   -- outside db.php, exists purely to mint docs.seq values
docs(col VARCHAR(20), id VARCHAR(100), data LONGTEXT NULL, updated BIGINT, updated_by CHAR(32) NULL,
     seq BIGINT UNSIGNED DEFAULT 0, PRIMARY KEY(col,id), INDEX(updated), INDEX(seq))
files(id CHAR(32) PK, name VARCHAR(255), type VARCHAR(100), size BIGINT, created BIGINT, user_id CHAR(32))
login_attempts(ip VARCHAR(45) PK, count INT, reset_at BIGINT)   -- new: replaces Node's in-memory rate-limit Map,
                                                                  -- needed because PHP has no state across requests
                                                                  -- on typical shared hosting
```
`docs.data IS NULL` is a **tombstone**: the doc existed and was deleted, but the row is kept (not hard-deleted) so `/api/changes` can tell a client that missed the live moment "this id was deleted," the same information SSE would have pushed as `{deleted:true}`. `/api/state` and any "list live docs" query always filter `WHERE data IS NOT NULL`; only `/api/changes` sees tombstones. Tombstones aren't purged by the app itself yet — `cron/backup.php` (item 7) should delete tombstones older than ~30 days as routine cleanup, after the backup completes.

### Polling cursor: `seq`, not `updated` (correction made after a second architecture review)

The first pass at `/api/changes` used the raw `updated` epoch-ms timestamp as the cursor (`WHERE updated > since`). **This is racy and was corrected**: two writes can land in the same millisecond under concurrent requests (e.g. a drag-and-drop task-priority reorder firing several `PUT`s back to back), and a strict `updated > since` query can then permanently miss whichever row was delivered second, since no cursor value derived from that shared timestamp can ever be `<` it again.

Fix: `docs.seq`, minted from the `doc_seq` AUTO_INCREMENT ticker table **inside the same transaction** as each `putDoc`/`delDoc` write (a row's own primary key can't be reused for this, because `INSERT ... ON DUPLICATE KEY UPDATE` does not advance an existing row's auto-increment value — only a fresh `INSERT` does). `/api/changes?since=` and the `cursor` both `/api/state` and `/api/changes` return are **seq values, not timestamps**.

This still leaves one smaller, much rarer race: minting a seq and committing the row are two separate moments, so two transactions can interleave such that the higher-seq write commits first. A poller whose cursor had already advanced past that higher seq would then never see the lower-seq row under a strict `seq > cursor` query. `docsSince()` closes this with a small (20-row) overlap behind the client's cursor — cheap and safe because `db.apply()` is idempotent, so redelivering a handful of already-seen rows costs nothing. This is a deliberate, proportionate choice for this app's scale (a venue's internal crew, not a high-concurrency system), not a full transaction-visibility watermark. Full reasoning lives in `lib/db.php`'s `docsSince()`/`putDoc()`/`delDoc()` docblocks — read those before touching this code.

**Key behavioral changes from the Node version** (all already decided with the user, not open questions):
1. SSE → polling, as above.
2. `password_hash()`/`password_verify()` (PHP native, bcrypt/argon2id) replaces hand-rolled scrypt — fresh deployment, no users to migrate.
3. DB-backed `login_attempts` table replaces the in-memory rate-limit `Map`, since PHP processes don't persist state between requests on shared hosting.
4. Session tokens stay a custom DB-backed scheme (hash stored server-side, raw token in an HttpOnly/SameSite=Lax cookie, ~30 day expiry) rather than switching to native `$_SESSION`, so expiry stays under our control rather than the host's `session.gc_maxlifetime`.
5. Nightly backup and work-order sheet sync move from Node `setInterval` timers to cPanel Cron Jobs invoking the PHP CLI scripts above.
6. Backup uses a **pure-PHP dump** (PDO `SELECT` → generated `INSERT` statements per table, written to a timestamped `.sql` file, plus a recursive copy of `private/uploads/`) rather than shelling out to `mysqldump`, since `exec`/`shell_exec` are frequently disabled on budget shared hosting.
7. Email alerts default to PHP's built-in `mail()` (cPanel wires this up out of the box) instead of configuring SMTP by hand — simpler, and avoids needing any third-party library, consistent with the original app's one-dependency philosophy (it used exactly `nodemailer`; this port uses zero PHP dependencies).

**Work order sheet sync**: `lib/workorder_mapping.php` ports `workorder-sync.js`'s mapping rules 1:1 (status→column table, priority→rank, date parsing including the blank-date placeholder guard, note synthesis, header-mismatch abort) and `lib/apps_script_client.php` ports `apps-script-client.js`'s GET-with-shared-secret-token fetch via cURL. `cron/workorder_sync.php` is the thin CLI entry point that wires them together against the real DB. Merge/orphan-delete logic against MySQL mirrors the already-tested Node logic: sheet-owned fields (title/col/assignee/due/room/notes/doneAt/created) are overwritten on each sync; board-owned fields (items/files/links/waiting) are preserved untouched on update and only defaulted to `[]` on first creation. This is the same design validated earlier by the Node integration test.

**PHP-specific gotcha to watch for when writing `workorder_mapping.php`**: the JS version groups parsed rows by column, sorts each group by priority, then assigns `rank` by mutating each row object in place (`list.forEach((p,idx)=>p.task.rank=idx)`) — this works in JS because objects are reference types, so the same object also sitting in the flat `parsed`/`tasks` array sees the mutation. **PHP arrays are copy-on-write, not references** — the equivalent `foreach ($list as &$p) $p['task']['rank'] = $idx;` only mutates the copies living inside the grouped-by-column array, not any separate flat array built earlier from the same data. Build the final `tasks`/`ids` return value **from the grouped-and-ranked arrays**, not from an original flat list assembled before ranking — otherwise every row silently gets `rank` reset to whatever default it had before sorting, in every column. This is exactly the kind of bug that would pass a casual read and only surface as "tasks aren't in priority order" after real data is loaded — write a few synthetic-row test cases by hand (two rows in the same column with different priorities) and trace the rank assignment through by eye before trusting it, since there's no PHP interpreter here to run it and confirm.

**cURL gotcha for `apps_script_client.php`**: Google Apps Script Web App URLs typically respond with an HTTP redirect on the first hit before returning the actual JSON. Set `CURLOPT_FOLLOWLOCATION => true`, or the request will return redirect/HTML content instead of JSON (Node's `fetch()` follows redirects by default, which is why this wasn't an issue there).

## Backup script: pure-PDO dump, no shell_exec

`cron/backup.php` must not depend on `shell_exec`/`exec`/`mysqldump` — budget shared hosting frequently disables those functions entirely (`disable_functions` in `php.ini`), and there's no way to confirm in advance whether this host allows them. Instead:
- Dump **data only**, not schema — `DELETE FROM table;` followed by batched (e.g. 200 rows per statement) `INSERT INTO table (...) VALUES (...), (...), ...;` built from `PDO::query("SELECT * FROM $table")`, with every value passed through `PDO::quote()` (not `addslashes()`, which is charset-unaware and has real corruption edge cases with multi-byte text — `PDO::quote()` goes through the real driver escaping). Schema itself lives in a separate, version-controlled `schema.sql` (the `CREATE TABLE IF NOT EXISTS` statements already in `lib/db.php`'s `ensure_schema()` double as this) that's run once to provision a fresh DB; restoring a backup means "run `ensure_schema()`/`schema.sql` once, then import the data-only dump," not "the dump recreates its own schema."
- Copy `private/uploads/` recursively using `RecursiveDirectoryIterator`/`RecursiveIteratorIterator` — no shell dependency.
- Prune to the last N backups the same way the Node version does (lexicographically sort `eventboard-YYYY-MM-DD-HH-ii-ss` directory names, which sorts identically to chronological order since the format is zero-padded, and remove the oldest beyond the retention count).
- After the dump, also purge `docs` tombstones older than ~30 days (`DELETE FROM docs WHERE data IS NULL AND updated < now-30d`), since nothing else does this cleanup.

## PHP-on-shared-hosting gotchas to account for

- **Upload limits**: `php.ini` defaults (`upload_max_filesize=2M`, `post_max_size=8M`) are far below this app's 25MB default, and since `POST /api/files` sends the file as a raw request body (not multipart `$_FILES`), **`post_max_size` is what actually matters here, not `upload_max_filesize`**. Override via `public_html/.user.ini` (works under both PHP-FPM and mod_php, unlike `.htaccess php_value` which only works under mod_php and is increasingly rare on modern cPanel): `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`. `.user.ini` changes can take up to 5 minutes to take effect (FPM's `user_ini.cache_ttl`) — don't panic-debug an upload failure immediately after changing it.
- **MySQL connection charset**: the PDO DSN must include `;charset=utf8mb4` explicitly (already done in `lib/db.php`) — without it, the *connection* can silently negotiate a different charset than the table's even when the table itself is `utf8mb4`, corrupting emoji/smart-quotes in a way that's easy to miss until weeks after launch.
- **`password_hash()` algorithm**: using `PASSWORD_DEFAULT` (already done in `lib/auth.php`) is fine — it currently resolves to bcrypt on every supported PHP version and needs no extra extension, unlike explicitly requesting Argon2id (needs libargon2 compiled into the host's PHP build, not guaranteed present).
- **Timezone**: call `date_default_timezone_set('UTC')` explicitly in a shared bootstrap rather than relying on the host's `php.ini` default, and keep every timestamp epoch-ms (matching the frontend's `Date.now()`-based model) — never mix in local-timezone `date()` formatting for anything that round-trips through the API.
- **cPanel Cron Jobs run in the server's system timezone**, not something PHP controls — "3am" is whatever the cron schedule says, set directly in cPanel's Cron Jobs UI rather than a `BACKUP_HOUR` config value (that setting is intentionally dropped from this port; the Node version's self-rescheduling `BACKUP_HOUR` logic has no PHP/cron equivalent needed).
- **cPanel cron's PHP binary**: cron's `PATH` may point at a different PHP version/binary than the web vhost; cPanel often needs a full versioned path (e.g. `/usr/local/bin/php81`) rather than a bare `php` — confirm via cPanel's Cron Jobs UI once deployed, not something to guess at now.
- **File permissions**: FTP-created directories commonly land with restrictive permissions that may not be writable by the PHP process's user. After FTP upload, explicitly `chmod 755` `private/`, `private/uploads/`, `private/backups/` via cPanel's File Manager; PHP's own `mkdir()` calls in this codebase already pass `0755` explicitly rather than relying on umask defaults.
- **MySQL strict mode**: modern MySQL/MariaDB defaults to `STRICT_TRANS_TABLES`, which rejects (doesn't silently truncate) bad values — good, but means every `INSERT`/`UPDATE` needs the same length/type guards the Node version enforces in application code (`.slice(0, 80)` on names, `validEmail`/`checkNewPassword`, etc.) ported faithfully, not left to MySQL to "fix."
- **Mail deliverability**: PHP's `mail()` is simple and needs no dependency, but shared-IP cPanel hosts are more likely to land in spam without SPF/DKIM configured for the sending domain (cPanel's Email → Authentication page) — document this as a post-deploy check, not something the code itself can fix.
- **CLI vs web `$_SERVER`**: `cron/*.php` run under the PHP CLI SAPI, where web-only superglobals (`$_SERVER['REQUEST_URI']`, etc.) don't exist. Keep anything shared between `api.php` and the cron scripts (config loading, DB connection, timezone) free of web-only superglobal access; confine that to `api.php`/`lib/http.php` only.

## Secrets: what's committed vs. created on the server

- **Committed**: `config.sample.php` (placeholder template, no real values), all PHP application code, `index.html`, `.htaccess` files, this plan and the progress tracker.
- **Never committed, created directly on the server**: `config.php` (real DB host/name/user/password, real workorder sync token if used) — copy `config.sample.php` to `config.php` via cPanel's File Manager and fill in real values there, not through chat or git. The MySQL database/user themselves are created through cPanel's "MySQL® Databases" tool, which generates the actual credentials; those go straight into `config.php` on the server. I do not need and will not ask for the user's real DB credentials at any point — all code references them symbolically (`DB_HOST`, `DB_NAME`, etc., defined in `config.php`).

## Build order

1. `lib/db.php` + schema — smallest, most foundational, easiest to reason about without execution. **Done.**
2. `lib/auth.php` (sessions, password hashing, rate limiting) — security-critical, review carefully since it can't be executed locally. **Done.**
3. `api.php` routing + the straightforward CRUD endpoints (`/api/status`, `/api/state`, `/api/docs/*`, `/api/changes`). **Done**, with the `seq`-cursor correction described above.
4. Auth/admin endpoints (`/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me`, `/api/users`, `/api/test-email`).
5. File upload/download (`/api/files`, `/_blob/{id}`).
6. `index.html` poll-loop change (smallest frontend diff) — remember to seed the cursor from `/api/state`'s `cursor`, not `0` or `serverTime`.
7. `lib/workorder_mapping.php`, `lib/apps_script_client.php`, `cron/backup.php`, `cron/workorder_sync.php`, `cron/reset_password.php` — watch for the PHP array-reference gotcha and cURL redirect gotcha noted above.
8. `config.sample.php`, `.htaccess` files (root + `private/` + `cron/`, both Apache 2.2 and 2.4 deny syntax), and a deployment README section (cPanel cron setup steps, DB import steps, upload-size `.user.ini` notes, file permission notes for `private/uploads/`).

Since this can't be executed locally, each piece should be kept small and self-contained enough to review line-by-line for correctness (mirroring the already-verified Node logic wherever a direct analog exists), rather than relying on running it to catch bugs. The user performs the real test after FTP upload.

## Scheduled nightly resume agent (separate from the port itself)

Once this plan is approved, in addition to starting the PHP port, set up a cron-triggered cloud agent (via the `schedule` skill) that runs nightly at midnight and resumes this port autonomously if a prior session was cut off by token limits — checking progress (task list / repo state) and continuing rather than waiting for the user to say "continue."

## Verification

- No local PHP/MySQL execution is available, so verification is primarily careful manual code review plus structural checks (e.g. confirm `.htaccess` rewrite rules, confirm schema matches query usage) rather than running tests.
- Where a Node-side behavior was already verified with an automated check (e.g. the work-order sync's create/update/preserve-board-edits/orphan-delete logic), the PHP port should replicate the same test scenarios as plain comments or a small standalone checklist so the user (or a later session) can manually confirm equivalent behavior after upload.
- Final real-world verification happens after the user uploads via FTP and imports the schema: creating the first admin account, confirming polling-based live updates work across two browser tabs, uploading a file, and running each cron script manually once before relying on the schedule.
