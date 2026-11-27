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
- [ ] 4. Auth/admin endpoints (`/api/setup`, `/api/login`, `/api/logout`, `/api/password`, `/api/me`, `/api/users`, `/api/test-email`)
- [ ] 5. File upload/download endpoints (`POST /api/files`, `GET /_blob/{id}`)
- [ ] 6. `public_html/index.html`: swap EventSource for polling (`/api/changes?since=`)
- [ ] 7. Cron scripts: `scripts/backup.php`, `scripts/workorder_sync.php`, `scripts/reset_password.php`
- [ ] 8. `config.sample.php`, `.htaccess` files, deployment README section

Each run: commit with a message naming which numbered item(s) it advanced, push to `claude/php-rewrite`, then update this checklist in the same commit.
