<?php
/**
 * Front controller for Event Board's PHP API.
 *
 * This build step wires up the route table and the core, non-auth-specific
 * CRUD endpoints: GET /api/status, GET /api/state, PUT/DELETE
 * /api/docs/{col}/{id}, and GET /api/changes (the polling replacement for
 * the Node version's SSE /api/events, per docs/PHP_REWRITE_PLAN.md's
 * frontend contract). Auth/admin endpoints (/api/setup, /api/login, ...)
 * and file upload/download are separate build-order items and are not
 * registered here yet.
 *
 * Deferred to a later item: server.js's notifyChanges() (email alerts on
 * task/show changes) runs after a successful PUT /api/docs here. It depends
 * on a mailer that doesn't exist yet (item 4 wires up /api/test-email and,
 * per the plan, PHP's built-in mail()). Once that lands, hook it into the
 * PUT handler below the same way server.js does (fire-and-forget, after the
 * response is sent, never blocking or failing the request on a mail error).
 *
 * .htaccess routing /api/* here is item 8; until then this file is only
 * reachable by pointing a request at it directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/http.php';

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

/**
 * Whether the UI should show mail-dependent affordances (e.g. "send test
 * email"). The Node version reported `!!process.env.SMTP_HOST`; this port
 * uses PHP's built-in mail() instead of SMTP (see PHP_REWRITE_PLAN.md), so
 * there is no equivalent "is it configured" signal yet. Default to off
 * until a later item defines MAIL_ENABLED in config.php and wires up
 * /api/test-email to actually exercise it.
 */
function mail_enabled(): bool
{
    return defined('MAIL_ENABLED') && MAIL_ENABLED === true;
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
    check_doc_path($params['col'], $params['id']);
    $json = read_json_doc(600 * 1024);
    Q::putDoc($params['col'], $params['id'], $json, now_ms(), $user['id']);
    send_json(200, ['ok' => true]);
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

run_api($router);
