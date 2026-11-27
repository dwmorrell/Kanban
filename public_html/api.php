<?php
/**
 * Front controller for Event Board's PHP API.
 *
 * CRUD endpoints: GET /api/status, GET /api/state, PUT/DELETE
 * /api/docs/{col}/{id}, GET /api/changes (the polling replacement for the
 * Node version's SSE /api/events), auth/admin endpoints (/api/setup,
 * /api/login, /api/logout, /api/password, /api/me, /api/users,
 * /api/test-email), and the email-notification hook on doc writes.
 * File upload/download (/api/files, /_blob/{id}) is a separate build-order
 * item and is not registered here yet.
 *
 * .htaccess routing /api/* here is item 8; until then this file is only
 * reachable by pointing a request at it directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/mailer.php';

const COLLECTIONS = ['shows', 'tasks', 'config'];

/** @throws ApiError 404 not_found / 400 invalid_argument */
function check_doc_path(string $col, string $id): void
{
    if (!in_array($col, COLLECTIONS, true)) {
        throw new ApiError(404, 'not_found', 'Unknown collection.');
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $id)) {
        throw new ApiError(400, 'invalid_argument', 'Invalid card id.');
    }
}

/** Public user list for API responses, same shape as server.js's userPayload(). */
function user_payload(): array
{
    return array_map(
        static fn (array $u): array => [
            'id' => $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
        ],
        Q::listUsers()
    );
}

$router = new Router();

$router->add('GET', '/api/status', function (): void {
    $user = current_user();
    send_json(200, [
        'setup' => Q::countUsers() === 0,
        'user' => public_user($user),
        'mail' => mail_enabled(),
    ]);
});

$router->add('GET', '/api/state', function (array $params, ?array $user): void {
    // Map keyed by arbitrary doc id, built as stdClass (not an assoc array)
    // so json_encode always emits a JSON object even when a collection is
    // empty or happens to contain only numeric-looking ids — see
    // lib/http.php's file header for why that distinction matters.
    $docs = (object) ['shows' => (object) [], 'tasks' => (object) [], 'config' => (object) []];
    foreach (Q::allDocs() as $row) {
        if (property_exists($docs, $row['col'])) {
            $docs->{$row['col']}->{$row['id']} = decode_doc_data($row['data']);
        }
    }
    send_json(200, [
        'docs' => $docs,
        'users' => user_payload(),
        'me' => public_user($user),
        'mail' => mail_enabled(),
        // Seeds the frontend's first /api/changes poll cursor, so it doesn't
        // redeliver this entire snapshot again a moment later (harmless if
        // it did, since db.apply() is idempotent, but wasteful).
        'cursor' => Q::currentSeq(),
    ]);
}, 'user');

$router->add('PUT', '/api/docs/:col/:id', function (array $params, ?array $user): void {
    [$col, $id] = [$params['col'], $params['id']];
    check_doc_path($col, $id);
    $json = read_json_doc(600 * 1024);
    // Read in assoc mode here (not decode_doc_data()'s object mode): this is
    // only for reading a few known fields (title/waiting/assignee/etc.) in
    // notify_changes() below, never re-serialized whole. See lib/http.php's
    // file header on why that distinction matters.
    $beforeRaw = Q::getDoc($col, $id);
    $before = $beforeRaw !== null ? json_decode($beforeRaw, true) : null;
    Q::putDoc($col, $id, $json, now_ms(), $user['id']);
    send_json(200, ['ok' => true]);
    finish_response();
    if ($col !== 'config') {
        $after = json_decode($json, true);
        if (is_array($after)) {
            notify_changes($col, $id, is_array($before) ? $before : null, $after, $user);
        }
    }
}, 'user');

$router->add('DELETE', '/api/docs/:col/:id', function (array $params, ?array $user): void {
    check_doc_path($params['col'], $params['id']);
    Q::delDoc($params['col'], $params['id'], now_ms(), $user['id']);
    send_json(200, ['ok' => true]);
}, 'user');

$router->add('GET', '/api/changes', function (array $params, ?array $user): void {
    // `since` is a seq cursor (from /api/state's or a previous /api/changes
    // response's `cursor`), not a timestamp — see docsSince()'s docblock in
    // lib/db.php for why `updated` alone isn't safe to use as the cursor.
    $sinceRaw = $_GET['since'] ?? null;
    $since = (is_string($sinceRaw) && ctype_digit($sinceRaw)) ? (int) $sinceRaw : 0;

    $rows = Q::docsSince($since);
    $cursor = $since;
    $docs = [];
    foreach ($rows as $row) {
        $docs[] = [
            'col' => $row['col'],
            'id' => $row['id'],
            'data' => decode_doc_data($row['data']),
            'updated' => (int) $row['updated'],
        ];
        $cursor = max($cursor, (int) $row['seq']);
    }

    send_json(200, [
        'docs' => $docs,
        'users' => user_payload(),
        'cursor' => $cursor,
        'serverTime' => now_ms(),
    ]);
}, 'user');

/* ---------- auth ---------- */

$router->add('POST', '/api/setup', function (): void {
    if (Q::countUsers() > 0) {
        throw new ApiError(409, 'already_setup', 'The board already has an admin. Sign in instead.');
    }
    $b = read_json_body();
    $email = clean_email($b['email'] ?? '');
    if (!valid_email($email)) {
        throw new ApiError(400, 'invalid_email', 'Enter a valid email address.');
    }
    check_new_password($b['password'] ?? null);
    $id = new_id();
    $name = trim((string) ($b['name'] ?? ''));
    $name = $name !== '' ? substr($name, 0, 80) : $email;
    Q::addUser($id, $email, $name, 'admin', hash_password((string) $b['password']), now_ms());
    start_session($id);
    send_json(200, ['user' => public_user(Q::userById($id))]);
});

$router->add('POST', '/api/login', function (): void {
    rate_limit();
    $b = read_json_body();
    $user = Q::userByEmail(clean_email($b['email'] ?? ''));
    if ($user === null || !check_password((string) ($b['password'] ?? ''), $user['pass'])) {
        throw new ApiError(401, 'bad_login', 'Wrong email or password.');
    }
    start_session($user['id']);
    send_json(200, ['user' => public_user($user)]);
});

$router->add('POST', '/api/logout', function (): void {
    end_session();
    send_json(200, ['ok' => true]);
});

/* ---------- your own account ---------- */

$router->add('PATCH', '/api/me', function (array $params, ?array $user): void {
    $b = read_json_body();
    if (is_string($b['name'] ?? null)) {
        $name = trim($b['name']);
        Q::setUserName($name !== '' ? substr($name, 0, 80) : $user['email'], $user['id']);
    }
    // No broadcast needed: unlike Node's SSE push, every /api/changes poll
    // already re-fetches the full user list fresh (see user_payload() call
    // there), so the next poll picks this up automatically.
    send_json(200, ['user' => public_user(Q::userById($user['id']))]);
}, 'user');

$router->add('POST', '/api/password', function (array $params, ?array $user): void {
    rate_limit();
    $b = read_json_body();
    if (!check_password((string) ($b['current'] ?? ''), $user['pass'])) {
        throw new ApiError(400, 'bad_password', 'Your current password is wrong.');
    }
    check_new_password($b['next'] ?? null);
    Q::setUserPass(hash_password((string) $b['next']), $user['id']);
    Q::delUserSessionsExcept($user['id'], $user['sessionTokenHash']);
    send_json(200, ['ok' => true]);
}, 'user');

/* ---------- admin: logins ---------- */

$router->add('POST', '/api/users', function (): void {
    $b = read_json_body();
    $email = clean_email($b['email'] ?? '');
    if (!valid_email($email)) {
        throw new ApiError(400, 'invalid_email', 'Enter a valid email address.');
    }
    if (Q::userByEmail($email) !== null) {
        throw new ApiError(409, 'exists', "$email already has a login.");
    }
    check_new_password($b['password'] ?? null);
    $role = ($b['role'] ?? null) === 'admin' ? 'admin' : 'member';
    $name = trim((string) ($b['name'] ?? ''));
    Q::addUser(new_id(), $email, $name !== '' ? substr($name, 0, 80) : $email, $role, hash_password((string) $b['password']), now_ms());
    // No broadcast needed here either — see the /api/me handler's comment above.
    send_json(200, ['users' => user_payload()]);
}, 'admin');

$router->add('PATCH', '/api/users/:id', function (array $params, ?array $me): void {
    $target = Q::userById($params['id']);
    if ($target === null) {
        throw new ApiError(404, 'not_found', 'That login no longer exists.');
    }
    $b = read_json_body();
    if (is_string($b['name'] ?? null)) {
        $name = trim($b['name']);
        Q::setUserName($name !== '' ? substr($name, 0, 80) : $target['email'], $params['id']);
    }
    $role = $b['role'] ?? null;
    if ($role === 'admin' || $role === 'member') {
        if ($role === 'member' && $target['role'] === 'admin' && Q::countAdmins() <= 1) {
            throw new ApiError(400, 'last_admin', 'The board needs at least one admin.');
        }
        Q::setUserRole($role, $params['id']);
    }
    if (array_key_exists('password', $b)) {
        check_new_password($b['password']);
        Q::setUserPass(hash_password((string) $b['password']), $params['id']);
        if ($params['id'] !== $me['id']) {
            Q::delUserSessions($params['id']);
        }
    }
    // No broadcast needed here either — see the /api/me handler's comment above.
    send_json(200, ['users' => user_payload()]);
}, 'admin');

$router->add('DELETE', '/api/users/:id', function (array $params, ?array $me): void {
    if ($params['id'] === $me['id']) {
        throw new ApiError(400, 'self', "You can't remove your own login.");
    }
    if (Q::userById($params['id']) !== null) {
        Q::delUser($params['id']);
        // No SSE streams to close here (polling, not push) — nothing else to do.
    }
    // No broadcast needed here either — see the /api/me handler's comment above.
    send_json(200, ['users' => user_payload()]);
}, 'admin');

$router->add('POST', '/api/test-email', function (array $params, ?array $user): void {
    if (!mail_enabled()) {
        throw new ApiError(400, 'mail_off', 'Email is not set up. Define MAIL_ENABLED in config.php and restart.');
    }
    try {
        send_mail($user['email'], 'Event Board test email', 'Email alerts from Event Board are working.');
    } catch (Throwable $e) {
        throw new ApiError(502, 'mail_failed', 'The email could not be sent. The mail server said: ' . $e->getMessage());
    }
    send_json(200, ['ok' => true, 'to' => $user['email']]);
}, 'admin');

run_api($router);
