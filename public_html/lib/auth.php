<?php
/**
 * Sessions, password hashing, and rate limiting for Event Board.
 *
 * Mirrors the session/password/rate-limit logic from the original Node
 * server.js (archive/node-event-board), with the behavioral changes already
 * decided in docs/PHP_REWRITE_PLAN.md:
 *
 *  - password_hash()/password_verify() (PASSWORD_DEFAULT: bcrypt today,
 *    transparently upgrades to argon2id if a future PHP version changes the
 *    default) replaces the hand-rolled scrypt scheme. There are no existing
 *    users to migrate, so no legacy-format fallback is needed.
 *  - Rate limiting is backed by the `login_attempts` table (via Q) instead
 *    of an in-memory Map, since a PHP process does not persist state
 *    between requests on shared hosting.
 *  - Session tokens stay the same custom DB-backed scheme as the Node
 *    version: a random token lives in an HttpOnly/SameSite=Lax cookie, and
 *    only its SHA-256 hash is ever stored server-side (sessions.token_hash).
 *
 * `ApiError` is the shared exception type for HTTP-shaped failures (status
 * + machine-readable code + user-facing message), equivalent to server.js's
 * `fail()` helper. lib/http.php and api.php (later build-order steps) catch
 * it the same way server.js's top-level request handler catches `fail()`
 * errors, to turn it into a JSON error response.
 *
 * Optional config.php constants (all have safe defaults if undefined):
 *   TRUST_PROXY    (bool) trust CF-Connecting-IP / X-Forwarded-* headers
 *   COOKIE_SECURE  (bool) force the session cookie's Secure flag on
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ApiError extends RuntimeException
{
    public int $status;
    public string $apiCode;

    public function __construct(int $status, string $apiCode, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->apiCode = $apiCode;
    }
}

const SESSION_COOKIE = 'eb_session';
const SESSION_DAYS = 30;
const RATE_LIMIT_WINDOW_MS = 15 * 60 * 1000;
const RATE_LIMIT_MAX_ATTEMPTS = 10;

/** 32 lowercase hex chars — matches the CHAR(32) id columns in the schema. */
function new_id(): string
{
    return bin2hex(random_bytes(16));
}

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

function hash_token(string $token): string
{
    return hash('sha256', $token);
}

function hash_password(string $password): string
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        // Only fails on a broken PHP install; not a user-facing condition.
        throw new RuntimeException('password_hash() failed');
    }
    return $hash;
}

function check_password(string $password, string $stored): bool
{
    return password_verify($password, $stored);
}

/** @throws ApiError 400 weak_password if $password doesn't meet the bar. */
function check_new_password(mixed $password): void
{
    if (!is_string($password) || strlen($password) < 8) {
        throw new ApiError(400, 'weak_password', 'Passwords need at least 8 characters.');
    }
    if (strlen($password) > 200) {
        throw new ApiError(400, 'weak_password', 'That password is too long.');
    }
}

/** Shapes a `users` row for API responses — never exposes `pass`. */
function public_user(?array $u): ?array
{
    if ($u === null) {
        return null;
    }
    return [
        'id' => $u['id'],
        'email' => $u['email'],
        'name' => $u['name'],
        'role' => $u['role'],
    ];
}

function client_ip(): string
{
    if (defined('TRUST_PROXY') && TRUST_PROXY) {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cf !== '') {
            return $cf;
        }
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function is_https_request(): bool
{
    if (defined('COOKIE_SECURE') && COOKIE_SECURE) {
        return true;
    }
    if (defined('TRUST_PROXY') && TRUST_PROXY && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    // Apache sets this directly when it terminates TLS itself, which is the
    // expected setup on plain shared cPanel hosting (no reverse proxy).
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function set_session_cookie(string $value, int $maxAgeSeconds): void
{
    $parts = [
        SESSION_COOKIE . '=' . $value,
        'Path=/',
        'HttpOnly',
        'SameSite=Lax',
        'Max-Age=' . $maxAgeSeconds,
    ];
    if (is_https_request()) {
        $parts[] = 'Secure';
    }
    header('Set-Cookie: ' . implode('; ', $parts), false);
}

/** Starts a new session for $userId and sets the session cookie. */
function start_session(string $userId): void
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = now_ms() + SESSION_DAYS * 86400000;
    Q::addSession(hash_token($token), $userId, $expires);
    set_session_cookie($token, SESSION_DAYS * 86400);
}

/** Deletes the current session (if any) and clears the cookie. Used by /api/logout. */
function end_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (is_string($token) && $token !== '') {
        Q::delSession(hash_token($token));
    }
    set_session_cookie('', 0);
}

/**
 * The signed-in user for this request, or null. Also carries
 * `sessionTokenHash` (the sessions.token_hash of the current session) so
 * callers like /api/password can keep this one session alive while
 * revoking all others — mirrors server.js's `u.sessionToken` (which, there
 * too, actually held the hash, not the raw cookie value).
 */
function current_user(): ?array
{
    maybe_purge_expired();
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }
    $tokenHash = hash_token($token);
    $session = Q::getSession($tokenHash);
    if ($session === null) {
        return null;
    }
    $now = now_ms();
    if ((int) $session['expires'] < $now) {
        return null;
    }
    if ((int) $session['expires'] - $now < (SESSION_DAYS - 1) * 86400000) {
        Q::touchSession($now + SESSION_DAYS * 86400000, $tokenHash);
    }
    $user = Q::userById($session['user_id']);
    if ($user === null) {
        return null;
    }
    $user['sessionTokenHash'] = $tokenHash;
    return $user;
}

/** @throws ApiError 401 signed_out if nobody is signed in. */
function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        throw new ApiError(401, 'signed_out', 'Please sign in again.');
    }
    return $user;
}

/** @throws ApiError 401 signed_out / 403 forbidden */
function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        throw new ApiError(403, 'forbidden', 'Only admins can do that.');
    }
    return $user;
}

/**
 * DB-backed replacement for server.js's in-memory `loginAttempts` Map:
 * at most RATE_LIMIT_MAX_ATTEMPTS calls per IP per RATE_LIMIT_WINDOW_MS.
 * Call once per sign-in attempt, before checking credentials.
 *
 * @throws ApiError 429 rate_limited
 */
function rate_limit(): void
{
    maybe_purge_expired();
    $ip = client_ip();
    $now = now_ms();
    $row = Q::getLoginAttempt($ip);
    $count = $row !== null ? (int) $row['count'] : 0;
    $resetAt = $row !== null ? (int) $row['reset_at'] : ($now + RATE_LIMIT_WINDOW_MS);
    if ($now > $resetAt) {
        $count = 0;
        $resetAt = $now + RATE_LIMIT_WINDOW_MS;
    }
    $count++;
    Q::upsertLoginAttempt($ip, $count, $resetAt);
    if ($count > RATE_LIMIT_MAX_ATTEMPTS) {
        throw new ApiError(429, 'rate_limited', 'Too many sign-in attempts. Wait 15 minutes and try again.');
    }
}

/**
 * Opportunistic cleanup of expired sessions and stale login_attempts rows.
 * server.js did this on an hourly setInterval; a PHP process on shared
 * hosting has no equivalent background timer, so instead this runs with
 * low probability on calls that already hit the DB (rate_limit(),
 * current_user()). Both deletes are plain range deletes, so running them
 * concurrently from multiple requests is harmless.
 */
function maybe_purge_expired(): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }
    $now = now_ms();
    Q::purgeSessions($now);
    Q::purgeLoginAttempts($now);
}
