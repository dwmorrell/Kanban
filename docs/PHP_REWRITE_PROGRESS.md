# PHP rewrite progress tracker

See `docs/PHP_REWRITE_PLAN.md` for the full plan and architecture decisions.

This file is the source of truth for what's done. A nightly scheduled agent
resumes this work by reading this checklist, picking the first unchecked
item, implementing it, checking it off, committing, and pushing. If a run
gets interrupted partway through an item, leave it unchecked and the next
run will redo/finish it rather than skipping ahead.

- [x] 1. `lib/db.php` + MySQL schema (users, sessions, docs, files, login_attempts)
- [x] 2. `lib/auth.php` (DB-backed sessions, password_hash/verify, rate limiting)
  - Note for whoever builds item 3/4: `require_user()`/`require_admin()` throw `ApiError`
    (status + apiCode + message) on auth failure — the route dispatcher in `api.php`
    should catch `ApiError` and turn it into a JSON error response (mirrors server.js's
    top-level `fail()` catch). `start_session()`/`end_session()` already set/clear the
    `eb_session` cookie; call them from `/api/setup`, `/api/login`, `/api/logout`.
    `rate_limit()` call before checking credentials in `/api/login` and `/api/password`.
- [x] 3. `api.php` routing + core CRUD endpoints (`/api/status`, `/api/state`, `/api/docs/{col}/{id}`, `/api/changes`)
  - Note for whoever builds item 4/5: `public_html/lib/http.php` has the `Router`/`run_api()` dispatcher,
    `read_json_body()` (assoc array, for reading a few scalar fields out of a body — login/setup/password/
    user edits), `read_json_doc()` (object-mode round trip, for storing a whole doc body verbatim-shaped),
    and `decode_doc_data()` (object-mode decode, for re-emitting a stored doc in a JSON response). Register
    new routes on the same `$router` in `api.php` with `$router->add(METHOD, pattern, handler, authLevel)`
    (`authLevel` is `null`, `'user'`, or `'admin'`); call `run_api($router)` only once, at the bottom, after
    all routes are registered.
  - Pitfall already hit and fixed here, worth knowing before adding more JSON-body-returning endpoints:
    `json_decode($x, true)` (assoc mode) cannot distinguish a JSON `{}` from `[]`, and an assoc array keyed
    by numeric-looking ids (e.g. doc id `"0"`) can silently re-encode as a JSON array instead of an object.
    Only use assoc mode for bodies you'll read a few fields out of and never re-serialize whole; anything
    that gets echoed back (a stored doc, a map keyed by arbitrary id) must decode in object mode
    (`json_decode($x)`, stdClass for objects) the way `read_json_doc()`/`decode_doc_data()` do, and a
    map-by-id must be built as `(object) [...]`/`$obj->{$key} = ...`, not a plain assoc array — see
    `/api/state`'s `$docs` construction in `api.php` for the pattern. Verified against a real MariaDB
    instance (installed in-sandbox for this run) with ids `"0"`/`"1"` and an empty-object doc.
  - Also fixed: route `:param` segments are decoded individually, after matching (mirrors server.js's
    `decodeURIComponent(v)` on each captured group), not by decoding the whole request path up front —
    otherwise a literal `%2F` inside an id would be decoded into a path separator before routing even runs,
    changing which route matches (and the error code) instead of reaching `check_doc_path()`'s validation.
  - Deferred to item 4 on purpose: server.js's `notifyChanges()` (email alerts after a doc PUT) isn't wired
    into the `PUT /api/docs/:col/:id` handler yet, since it needs a mailer that doesn't exist until
    `/api/test-email` and PHP's `mail()` are built. `api.php`'s file header has a note; wire it in as a
    fire-and-forget call after `send_json()`, same as server.js does, once the mailer exists.
  - `mail_enabled()` in `api.php` (backing the `mail` field in `/api/status`/`/api/state`) currently always
    returns `false` unless a `MAIL_ENABLED` constant is defined truthy in `config.php` — there's no such
    constant yet (config.sample.php is item 8). Revisit once item 4/8 settle on how mail readiness is
    actually signaled.
  - **Correction applied after a second architecture review, to already-committed item-3 code**: the
    original `/api/changes?since=<ms>` cursor was the raw `updated` epoch-ms timestamp. That's racy —
    two writes can land in the same millisecond under concurrent requests, and a strict `updated > since`
    query can then permanently miss whichever one was delivered second. Fixed by adding a `doc_seq`
    AUTO_INCREMENT ticker table and a `docs.seq` column (minted inside the same transaction as each
    `putDoc`/`delDoc` write, since `ON DUPLICATE KEY UPDATE` doesn't advance a row's own primary-key
    auto-increment). `/api/changes?since=` and the `cursor` it/`/api/state` return are now **seq values,
    not timestamps** — `docsSince()` in `lib/db.php` also applies a small (20-row) overlap behind the
    client's cursor to cover the much smaller residual race where two transactions commit out of
    mint-order; this is safe because the frontend's `db.apply(col,id,data)` is idempotent, so redelivering
    a few already-seen rows costs nothing. Full reasoning is in both functions' docblocks in `lib/db.php`.
    **If you are building item 6 (the frontend poll loop): use the `cursor` field from `/api/state` to seed
    the first poll, and the `cursor` field from each `/api/changes` response to advance it. Do not use
    `serverTime` as the cursor** — it's still returned for informational purposes only.
- [x] 4. Auth/admin endpoints (`/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me`, `/api/users`, `/api/test-email`)
  - Also added `lib/mailer.php` (`mail_enabled()`, `send_mail()`, `skip_mail()`, `notify_changes()` — ports server.js's
    mailer/notifyChanges 1:1, via PHP's `mail()`) and wired it into `PUT /api/docs/:col/:id` in `api.php`, closing the
    gap item 3 deliberately deferred. `mail_enabled()` still just checks a `MAIL_ENABLED` constant that doesn't exist
    until `config.sample.php` (item 8) defines it — until then email alerts are off and every mail-related code path
    is a no-op, which is safe to leave as-is.
  - Added `lib/http.php`'s `finish_response()` (wraps `fastcgi_finish_request()` where available) and call it right
    after `send_json()` in the `PUT /api/docs` handler, before `notify_changes()`. **Read this before touching any
    other fire-and-forget-after-response code**: plain PHP (unlike Node) normally buffers the whole response until
    the script ends, so without this, every doc edit would wait on `mail()` to finish even though the client already
    "has" its 200 response conceptually. Only works under PHP-FPM; under mod_php/suPHP it's a no-op (request just
    waits on the mail call instead) — harmless given `mail_enabled()` defaults to off, just slower once mail is on.
  - Added `valid_email()`/`clean_email()` to `lib/auth.php` (ports server.js's `validEmail`/`cleanEmail`) — use these,
    don't re-invent email validation in a new route.
  - No `broadcast_users()`-equivalent exists or is needed: unlike Node's SSE push, every `/api/changes` poll already
    re-fetches the full user list fresh. If you're tempted to add a "broadcast on user change" mechanism for a later
    item, you very likely don't need to — check whether the next poll already covers it first.
  - For whoever builds item 5 (file upload/download): `Q` (`lib/db.php`) already has `addFile`/`getFile`/`delFile`
    ready to use; nothing new needed there. The upload endpoint's raw-request-body handling should reuse
    `read_raw_body()` from `lib/http.php` (same size-limit enforcement pattern as `read_json_doc()`), not
    `read_json_body()`/`read_json_doc()` (the uploaded bytes aren't JSON).
- [x] 5. File upload/download endpoints (`POST /api/files`, `GET /_blob/{id}`)
  - `GET /_blob/:id` requires auth (`'user'`) — same as server.js's version. Easy to miss since it's not under
    `/api/`, so the CSRF header check in `run_api()` doesn't apply to it either way (it's a GET), but the
    *auth* requirement is separate from that and still applies — don't register this route without an auth
    level if you ever touch it.
  - Upload reads the raw request body via `read_raw_body()` (`lib/http.php`), not `read_json_body()`/
    `read_json_doc()` — the bytes aren't JSON. MIME type comes from `$_SERVER['CONTENT_TYPE']`, filename from
    `$_SERVER['HTTP_X_FILENAME']` (urldecoded, then stripped of path separators/control chars, capped at 200
    chars) — mirrors server.js's header reads exactly.
  - `upload_dir()`/`max_upload_bytes()` (top of `api.php`) read `UPLOAD_DIR`/`MAX_UPLOAD_MB` constants with
    fallback defaults (`public_html/private/uploads`, 25MB) since `config.php` doesn't exist until item 8 —
    once it does, define those constants there rather than changing these functions.
- [x] 6. `public_html/index.html`: swap EventSource for polling (`/api/changes?since=`)
  - Copied `public/index.html` from `archive/node-event-board` into `public_html/index.html` verbatim,
    then replaced only `connectLive()`/`resync()` — a `git diff --no-index` against the archive file
    confirms that's the only change (matches the plan's "~5% changed" scope).
  - New `connectLive(cursor)` seeds `pollCursor` from the caller's argument (the main IIFE now calls
    `connectLive(s.cursor)` using `/api/state`'s `cursor` field, not `0` or `serverTime`, per the plan).
    `pollTick()` then calls `GET /api/changes?since=<pollCursor>` on a 3s `setTimeout` loop (not
    `setInterval`, so a slow response can't pile up overlapping requests), advances `pollCursor` from
    each response's own `cursor`, and feeds every returned doc straight into `db.apply(d.col,d.id,d.data)`
    — `data` is already `null` for a tombstone (deleted doc), so no separate `deleted` flag is needed,
    unlike the old `{type:'doc',deleted:true}` SSE message shape.
  - Users: every `/api/changes` response already carries a fresh full user list (server-side, nothing
    new needed there per item 4's note). To avoid an unconditional full `renderMain()`/`renderDrawer()`
    every 3s even when nothing changed (the old SSE push only fired on an actual user-list change),
    `pollTick()` keeps a `usersSnapshot` JSON string and only reassigns `users`/`me` and calls `refresh()`
    when it differs from the previous tick's. Doc changes don't need this treatment: `db.apply()` already
    only notifies the `onSnapshot` listeners registered in the main IIFE's `sub()` calls, which is exactly
    what triggered `refresh()` before, so an empty `docs` array in a poll response naturally triggers
    nothing extra.
  - Error handling mirrors the old `es.onerror`/`es.onopen` pair as closely as polling allows: a failed
    poll schedules `offline=true` (and a `/api/status` signed-out check) after a 5s debounce exactly like
    before, cleared on the next successful poll; a 401 (already turned into `signedOut()` by `API.req`
    itself) just returns without scheduling another poll, since the page is about to reload anyway.
  - Did not touch any other frontend logic (drag/drop, drawer rendering, uploads, admin actions, etc.) —
    only the live-update transport changed, per the plan.
- [x] 7. Cron scripts: `public_html/cron/backup.php`, `public_html/cron/workorder_sync.php`,
  `public_html/cron/reset_password.php` (plus `lib/workorder_mapping.php`, `lib/apps_script_client.php`)
  - Built under `public_html/cron/` per the plan's directory layout (not a top-level `scripts/`, which is
    reserved for the archived Node implementation) — the progress checklist's old item-7 title named
    `scripts/*.php` but the plan body's directory map says `public_html/cron/`; followed the plan body.
  - **This sandbox turned out to have PHP 8.3 and an installable MariaDB after all** (contrary to the
    plan's "no local PHP/MySQL" assumption written for earlier items) — `apt-get install -y mariadb-server`
    worked, and running it with `--skip-grant-tables` gave a real local MySQL-compatible server. Every
    script in this item was actually executed end-to-end against it (not just read-reviewed): schema
    creation, seeding users/docs/files/tombstones, running `backup.php`, importing the resulting
    `dump.sql` into a truncated-but-schema-intact DB and confirming row counts/tombstone/seq round-trip,
    confirming `doc_seq`'s AUTO_INCREMENT correctly resumes past a restored high-water mark (the exact
    failure mode `purgeTombstones`'s docblock and this item's backup design worry about), running
    `workorder_sync.php` against a tiny local PHP server standing in for the Apps Script Web App
    (including one 302 redirect hop, to exercise `CURLOPT_FOLLOWLOCATION`), verifying per-column rank
    assignment across multiple columns/priorities, verifying the board-owned-fields-preserved-on-update
    merge rule, verifying orphan tombstoning when a row disappears from the sheet, verifying the
    no-op-skip dedup check, verifying the header-mismatch guard throws, and running
    `reset_password.php`'s usage/weak-password/unknown-user/success paths. If a later item needs local
    MySQL again: `sudo apt-get install -y mariadb-server`, then
    `sudo -u mysql mariadbd --skip-grant-tables --socket=/var/run/mysqld/mysqld.sock &`, then connect as
    `root`/no password over `127.0.0.1`. No test `config.php` or test data/dirs were left committed —
    both were created under `public_html/` during testing and deleted afterward (confirmed via
    `git status` showing only the real source files below as untracked/modified).
  - Added `date_default_timezone_set('UTC')` to the top of `lib/db.php` (before it requires
    `config.php`) — the plan calls for this explicitly (PHP-on-shared-hosting gotchas section) but no
    prior item had added it anywhere yet, and this item's date math (`cron/backup.php`'s `date()`-based
    backup-folder timestamps, `lib/workorder_mapping.php`'s `strtotime()`-based date parsing) is the
    first code in the port that actually depends on it being UTC rather than the host's default.
    `lib/db.php` is required (transitively) by `api.php` and every cron script, so this is now set
    exactly once, early, everywhere.
  - Added `Q::purgeTombstones(int $beforeMs): int` to `lib/db.php` — the plan asks `cron/backup.php` to
    purge `docs` tombstones older than ~30 days after each backup completes; this is the first item that
    needed it, added as a normal `Q` method (raw SQL stays inside `lib/db.php`, not inline in the cron
    script) rather than a one-off query in `backup.php`.
  - `lib/workorder_mapping.php` avoids the PHP array-reference pitfall the plan calls out (JS mutates
    row objects in place via a by-column grouping; PHP arrays are copy-on-write) by grouping *indices*
    into the single canonical `$parsed` array and writing each row's `rank` back onto `$parsed[$idx]`
    directly, rather than ever copying row data into a second array — there is only one array holding
    each row, so there's nothing for the mutation to fail to reach. Verified by hand with multiple rows
    sharing a column at different priorities (see the MariaDB testing above) rather than just reasoning
    about it, since this is exactly the kind of bug the plan warned reads clean and only shows up with
    real multi-row data.
  - `lib/workorder_mapping.php` also fixes a subtler JS-vs-PHP truthiness gap the plan didn't call out:
    JS's `if (createdBy)` / `if (deliverable)` / `if (notes)` / `if (closingNotes)` guards are only false
    on an empty string, but PHP's `if ($x)` on the *string* `"0"` is false (PHP's classic falsy-string
    quirk) — a sheet cell containing literally `"0"` would then silently drop a note line under a naive
    port. Replaced each with an explicit `!== ''` check.
  - `lib/apps_script_client.php` sets `CURLOPT_FOLLOWLOCATION`/`CURLOPT_MAXREDIRS` per the plan's cURL
    gotcha note (Apps Script Web Apps answer the first hit with a redirect); confirmed with a local
    stand-in server that actually redirects once before returning JSON, not just by reading the code.
  - `cron/backup.php` dumps `doc_seq` as a full table (not just `docs`) — its rows are the only thing
    `docs.seq` values are minted from, and skipping it would let a post-restore `mintSeq()` hand out a
    seq number that collides with one a restored `docs` row already has. Table dump order
    (`users, sessions, doc_seq, docs, files, login_attempts`) matters only for `sessions.user_id`'s FK on
    `users.id`; verified the exact import order round-trips cleanly via `mysql ... < dump.sql`.
  - `cron/backup.php` treats a value's `NULL` specially (literal `NULL` in the generated SQL) rather
    than passing it through `PDO::quote()`, since `PDO::quote(null)`'s behavior isn't reliably "emit SQL
    NULL" across drivers — getting this wrong would turn `docs.data IS NULL` tombstones and nullable
    `updated_by`/`user_id` columns into empty strings on restore instead of NULL, silently breaking the
    tombstone contract `lib/db.php` documents.
  - No `.htaccess` added for `public_html/cron/` or `public_html/private/` in this item — the plan
    assigns both (root + `private/` + `cron/`, Apache 2.2 and 2.4 deny syntax) to item 8 specifically, so
    it's deliberately left for whoever builds that item next, not an oversight here.
  - `config.sample.php` doesn't exist yet (item 8), so `BACKUP_DIR`/`BACKUP_KEEP`/
    `WORKORDER_APPS_SCRIPT_URL`/`WORKORDER_APPS_SCRIPT_TOKEN`/`WORKORDER_SHEET_NAME`/
    `WORKORDER_SHEET_RANGE` are all read with the same `defined('X') ? X : default`/"off unless defined"
    fallback pattern `api.php`'s `upload_dir()`/`max_upload_bytes()` already established for item 5 —
    whoever builds item 8 should add these as documented, commented-out-by-default constants in
    `config.sample.php` rather than changing how the cron scripts read them.
- [x] 8. `config.sample.php`, `.htaccess` files, deployment README section
  - `public_html/config.sample.php` documents every constant any prior item's code reads via
    `defined('X')` (grepped across `lib/*.php`, `api.php`, `cron/*.php` to build the list):
    `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` (required), `BASE_URL`, `TRUST_PROXY`, `COOKIE_SECURE`,
    `UPLOAD_DIR`, `MAX_UPLOAD_MB`, `MAIL_ENABLED`, `MAIL_FROM`, `BACKUP_DIR`, `BACKUP_KEEP`,
    `WORKORDER_APPS_SCRIPT_URL`, `WORKORDER_APPS_SCRIPT_TOKEN`, `WORKORDER_SHEET_NAME`,
    `WORKORDER_SHEET_RANGE`. Deliberately does **not** add a `WORKORDER_SYNC_MINUTES` or
    `BACKUP_HOUR`-equivalent constant — per the plan, cron timing is set directly in cPanel's Cron
    Jobs UI, not read from config.php. `public_html/config.php` (the real, uncommitted file) was
    added to `.gitignore`.
  - `public_html/.htaccess` (root): `mod_rewrite` routes `/api/*` and `/_blob/*` to `api.php`
    (`$_SERVER['REQUEST_URI']` is unaffected by the rewrite, which is what `run_api()` parses for
    routing — verified, not just assumed, below); everything else serves as a static file.
    `Options -Indexes`, `DirectoryIndex index.html`, and a `<FilesMatch>` block (both Apache
    2.2/2.4 deny syntax) block direct web access to `config.php`/`config.sample.php` by name as
    defense in depth.
  - `public_html/private/.htaccess` and `public_html/cron/.htaccess`: deny-all (both Apache
    2.2/2.4 syntax) per the plan. `private/`'s rule applies recursively to `uploads/`/`backups/`,
    so no separate `.htaccess` was added in either (confirmed by the live test below). Added
    `.gitkeep` to both so they exist on a fresh FTP upload rather than being created on first
    write by `upload_dir()`/`backup_dir()`.
  - `public_html/.user.ini`: raises `upload_max_filesize`/`post_max_size`/`memory_limit`/
    `max_execution_time` with headroom over `config.sample.php`'s default `MAX_UPLOAD_MB=25`, per
    the plan's upload-limit gotcha (`post_max_size` is what matters here, not
    `upload_max_filesize`, since uploads are a raw POST body, not multipart). Shipped as a real,
    committed file (not a `.sample`) since it holds no secrets, unlike `config.php`.
  - README.md: added a new top-level section "Deploying the PHP version to shared cPanel hosting"
    (before "Updating"), covering DB creation, FTP upload, `config.php` setup, `private/`
    permissions (`755`), cPanel Cron Jobs for both scripts (with the "cPanel's cron PHP binary may
    differ from the vhost's" gotcha from the plan), a no-SSH password-reset workaround (one-off
    cron job), the `.user.ini` upload-size note, backup/restore steps, and an SPF/DKIM
    deliverability note. Left the existing Node/Docker instructions above it completely untouched
    — this is an additional deployment track for this same repo, not a replacement.
  - **This sandbox again had an installable MariaDB (as item 7 found) — this time Apache 2.4 with
    `mod_rewrite`/`mod_php` was also installable, so this item was verified by actually serving
    `public_html/` through real Apache with `AllowOverride All` (not just reasoning about the
    `.htaccess` syntax):** confirmed `GET /` serves `index.html` (200), `GET /api/status` through
    the rewrite returns the real JSON response with `$_GET`/query-string params intact, `/private/`,
    `/private/uploads/.gitkeep`, `/cron/backup.php`, `/config.php`, and `/config.sample.php` all
    return 403, and a `PUT /api/docs/...` without the `x-requested-with` header is still blocked
    with 403 by `run_api()`'s CSRF check even when reached through the rewrite. Also ran, against
    the same real MariaDB instance, through the full stack with a `config.php` copied from
    `config.sample.php` (fresh schema creation, `/api/setup`, `/api/login`, a doc `PUT`/`DELETE`
    and confirming both the live doc and the tombstone show up correctly in `/api/changes`,
    `POST /api/files` + `GET /_blob/{id}` round-tripping real bytes, `cron/backup.php` producing a
    real `dump.sql` with both the tombstone and the files row from this test, and
    `cron/reset_password.php`'s success path) — all against the directory defaults
    `config.sample.php`'s comments describe (`UPLOAD_DIR`/`BACKUP_DIR` undefined, so
    `private/uploads`/`private/backups`). Apache, MariaDB, and the test `config.php`, uploaded
    test files, and generated backup directory were all stopped/removed afterward; `git status`
    confirms only the real source files below are tracked/new.
  - All 8 build-order items are now complete. Final real-world verification (the actual FTP
    upload, cPanel DB/cron setup, and a first live end-to-end check with real credentials) is the
    user's step per the plan — nothing further for a nightly resume run to do here unless the user
    reports a problem.

Each run: commit with a message naming which numbered item(s) it advanced, push to `claude/php-rewrite`, then update this checklist in the same commit.
