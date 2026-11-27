<?php
/**
 * Request/response plumbing for Event Board's PHP API: JSON body parsing,
 * JSON responses, and a tiny route table. Mirrors the shape of server.js's
 * `send()`/`readJson()`/`route()` helpers and its top-level request handler
 * (archive/node-event-board/server.js), adapted to PHP's one-request-per-call
 * model (no streaming request bodies, no persistent connections).
 *
 * PHP-specific pitfall this file exists partly to avoid: json_decode($x, true)
 * turns a JSON "{}" into a PHP [] indistinguishable from a JSON "[]", and an
 * assoc array keyed by numeric-looking doc ids (e.g. "0", "1") can silently
 * re-encode as a JSON array instead of an object. Anything that will be
 * re-serialized and sent back out (doc bodies, the docs-by-id maps in
 * /api/state and /api/changes) must therefore decode in *object* mode
 * (json_decode($x) without the true flag, or stdClass for maps-by-id), not
 * assoc mode. See decode_doc_data() and read_json_doc() below.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Reads the raw request body, enforcing $limit the same way server.js's readBody() does. */
function read_raw_body(int $limit): string
{
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $limit) {
        throw new ApiError(413, 'too_large', 'That is over the size limit.');
    }
    $body = file_get_contents('php://input');
    if ($body === false) {
        $body = '';
    }
    if (strlen($body) > $limit) {
        throw new ApiError(413, 'too_large', 'That is over the size limit.');
    }
    return $body;
}

/**
 * Parses a JSON object request body into an associative array, for
 * endpoints that only read a handful of scalar fields out of it (login,
 * setup, password, user edits, etc. — item 4). Not for anything that will
 * be re-serialized whole; use read_json_doc() for that.
 *
 * @throws ApiError 400 invalid_argument / 413 too_large
 */
function read_json_body(int $limit = 1024 * 1024): array
{
    $text = read_raw_body($limit);
    if ($text === '') {
        $text = '{}';
    }
    $decoded = json_decode($text);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new ApiError(400, 'invalid_argument', 'The request was not valid JSON.');
    }
    if (!($decoded instanceof stdClass)) {
        throw new ApiError(400, 'invalid_argument', 'Expected a JSON object.');
    }
    $assoc = json_decode($text, true);
    return is_array($assoc) ? $assoc : [];
}

/**
 * Validates that the request body is a JSON object and returns it
 * re-serialized as compact JSON text, ready to store in docs.data.
 * Mirrors server.js's `JSON.stringify(await readJson(req, 600*1024))`, but
 * decodes in object mode (not assoc mode) so an empty object round-trips as
 * "{}" rather than collapsing into "[]".
 *
 * @throws ApiError 400 invalid_argument / 413 too_large
 */
function read_json_doc(int $limit): string
{
    $text = read_raw_body($limit);
    if ($text === '') {
        $text = '{}';
    }
    $decoded = json_decode($text);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new ApiError(400, 'invalid_argument', 'The request was not valid JSON.');
    }
    if (!($decoded instanceof stdClass)) {
        throw new ApiError(400, 'invalid_argument', 'Expected a JSON object.');
    }
    $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new ApiError(400, 'invalid_argument', 'That data could not be stored.');
    }
    return $json;
}

/**
 * Decodes a stored docs.data value (or null, for a tombstone) for inclusion
 * in a JSON response. Uses object mode so stored objects/arrays keep their
 * original shape (see file header). Docs are only ever written through
 * read_json_doc(), which already validated them as JSON objects, so a
 * decode failure here means the row is corrupt, not a client error.
 */
function decode_doc_data(?string $raw): mixed
{
    if ($raw === null) {
        return null;
    }
    $decoded = json_decode($raw);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Corrupt doc JSON in docs.data.');
    }
    return $decoded;
}

function send_json(int $status, mixed $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function send_error(Throwable $e): void
{
    if ($e instanceof ApiError) {
        send_json($e->status, ['error' => $e->getMessage(), 'code' => $e->apiCode]);
        return;
    }
    error_log((string) $e);
    send_json(500, ['error' => 'Something went wrong on the server.', 'code' => 'error']);
}

/**
 * Decodes a single matched path segment, mirroring server.js's
 * `decodeURIComponent(v)` on each route param (including its 400 on a
 * malformed escape, where server.js catches the resulting URIError).
 *
 * @throws ApiError 400 invalid_argument
 */
function decode_uri_component(string $value): string
{
    if (!preg_match('/^(?:[^%]|%[0-9A-Fa-f]{2})*$/', $value)) {
        throw new ApiError(400, 'invalid_argument', 'Bad address.');
    }
    return rawurldecode($value);
}

/**
 * A tiny route table, equivalent to server.js's `route()`/`routes` array.
 * Handlers are `function(array $params, ?array $user): void` and are
 * expected to end by calling send_json() (or, for a future binary route
 * like /_blob/{id}, write their own output directly).
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, handler: callable, auth: ?string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, ?string $auth = null): void
    {
        $regex = '#^' . preg_replace('#:(\w+)#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'auth' => $auth];
    }

    /**
     * $path is matched still percent-encoded (like server.js matching
     * against url.pathname) — only the captured :param segments are
     * decoded, and only after a route matches. This keeps a literal %2F
     * inside an id from being decoded into a path separator before
     * routing, which would silently reroute the request instead of
     * reaching the id-format validation a handler like check_doc_path()
     * does.
     *
     * @throws ApiError 400 invalid_argument (malformed %-escape), 401/403
     *     (via require_user/require_admin), or 404 not_found
     */
    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = decode_uri_component($value);
                }
            }

            $user = null;
            if ($route['auth'] === 'admin') {
                $user = require_admin();
            } elseif ($route['auth'] === 'user') {
                $user = require_user();
            }

            ($route['handler'])($params, $user);
            return;
        }
        throw new ApiError(404, 'not_found', 'Not found.');
    }
}

/**
 * Entry point called by api.php. Sets the same defensive headers server.js
 * sets on every response, enforces the x-requested-with CSRF header on
 * non-GET /api/* requests, dispatches to $router, and turns any thrown
 * ApiError/Throwable into a JSON error response — mirroring server.js's
 * top-level try/catch.
 */
function run_api(Router $router): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Left percent-encoded here, matched as-is against route patterns;
        // only the captured :param segments get decoded, inside
        // Router::dispatch(). See decode_uri_component()'s docblock.
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if (str_starts_with($path, '/api/') && $method !== 'GET'
            && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'eventboard') {
            throw new ApiError(403, 'forbidden', 'Request blocked.');
        }

        $router->dispatch($method, $path);
    } catch (Throwable $e) {
        send_error($e);
    }
}
