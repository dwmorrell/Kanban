<?php
/**
 * PDO bootstrap and data-access layer for Event Board.
 *
 * Mirrors the `q` prepared-statement object from the original Node
 * server.js (archive/node-event-board), with two intentional additions
 * forced by the move to plain shared PHP hosting:
 *
 *  - `login_attempts` is a real table, not an in-memory Map, because a
 *    PHP process does not persist state between requests.
 *  - `docs.data` is NULLable. A row with data = NULL is a *tombstone*:
 *    the doc existed, was deleted, and the row is kept (instead of being
 *    removed) so that GET /api/changes?since=<ms> can tell long-poll
 *    clients "this id was deleted at <updated>" instead of silently
 *    losing the deletion. /api/state and allDocs() only ever see live
 *    (non-tombstoned) docs.
 *
 * Requires config.php (not committed; see config.sample.php, added in a
 * later rewrite step) to define: DB_HOST, DB_NAME, DB_USER, DB_PASS.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id CHAR(32) NOT NULL PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL DEFAULT \'\',
            role VARCHAR(20) NOT NULL DEFAULT \'member\',
            pass VARCHAR(255) NOT NULL,
            created BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id CHAR(32) NOT NULL,
            expires BIGINT NOT NULL,
            INDEX idx_sessions_user (user_id),
            CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS docs (
            col VARCHAR(20) NOT NULL,
            id VARCHAR(100) NOT NULL,
            data LONGTEXT NULL,
            updated BIGINT NOT NULL,
            updated_by CHAR(32) NULL,
            PRIMARY KEY (col, id),
            INDEX idx_docs_updated (updated)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS files (
            id CHAR(32) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(100) NOT NULL,
            size BIGINT NOT NULL,
            created BIGINT NOT NULL,
            user_id CHAR(32) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            count INT NOT NULL DEFAULT 0,
            reset_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * Data-access methods, grouped the way server.js's `q` object was.
 * Each call prepares and executes its own statement; nothing is cached
 * across requests, since nothing can be on typical shared hosting.
 */
final class Q
{
    private static function pdo(): PDO
    {
        return db();
    }

    private static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    private static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /* ---------- users ---------- */

    public static function countUsers(): int
    {
        return (int) self::one('SELECT COUNT(*) AS n FROM users')['n'];
    }

    public static function countAdmins(): int
    {
        return (int) self::one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")['n'];
    }

    public static function userById(string $id): ?array
    {
        return self::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function userByEmail(string $email): ?array
    {
        return self::one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function listUsers(): array
    {
        // utf8mb4's default collation (e.g. utf8mb4_general_ci / _0900_ai_ci)
        // is already case-insensitive, so no COLLATE NOCASE equivalent is needed.
        return self::all('SELECT id, email, name, role, created FROM users ORDER BY name');
    }

    public static function addUser(string $id, string $email, string $name, string $role, string $pass, int $created): void
    {
        self::run(
            'INSERT INTO users (id, email, name, role, pass, created) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $email, $name, $role, $pass, $created]
        );
    }

    public static function setUserName(string $name, string $id): void
    {
        self::run('UPDATE users SET name = ? WHERE id = ?', [$name, $id]);
    }

    public static function setUserRole(string $role, string $id): void
    {
        self::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
    }

    public static function setUserPass(string $pass, string $id): void
    {
        self::run('UPDATE users SET pass = ? WHERE id = ?', [$pass, $id]);
    }

    public static function delUser(string $id): void
    {
        self::run('DELETE FROM users WHERE id = ?', [$id]);
    }

    /* ---------- sessions ---------- */

    public static function addSession(string $tokenHash, string $userId, int $expires): void
    {
        self::run(
            'INSERT INTO sessions (token_hash, user_id, expires) VALUES (?, ?, ?)',
            [$tokenHash, $userId, $expires]
        );
    }

    public static function getSession(string $tokenHash): ?array
    {
        return self::one('SELECT * FROM sessions WHERE token_hash = ?', [$tokenHash]);
    }

    public static function touchSession(int $expires, string $tokenHash): void
    {
        self::run('UPDATE sessions SET expires = ? WHERE token_hash = ?', [$expires, $tokenHash]);
    }

    public static function delSession(string $tokenHash): void
    {
        self::run('DELETE FROM sessions WHERE token_hash = ?', [$tokenHash]);
    }

    public static function delUserSessions(string $userId): void
    {
        self::run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    public static function delUserSessionsExcept(string $userId, string $tokenHash): void
    {
        self::run('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?', [$userId, $tokenHash]);
    }

    public static function purgeSessions(int $now): void
    {
        self::run('DELETE FROM sessions WHERE expires < ?', [$now]);
    }

    /* ---------- docs ---------- */

    /** Live (non-deleted) docs only — used for the initial /api/state load. */
    public static function allDocs(): array
    {
        return self::all('SELECT col, id, data FROM docs WHERE data IS NOT NULL');
    }

    /** Live doc data, or null if missing/deleted. */
    public static function getDoc(string $col, string $id): ?string
    {
        $row = self::one('SELECT data FROM docs WHERE col = ? AND id = ? AND data IS NOT NULL', [$col, $id]);
        return $row ? $row['data'] : null;
    }

    public static function putDoc(string $col, string $id, string $data, int $updated, ?string $updatedBy): void
    {
        self::run(
            'INSERT INTO docs (col, id, data, updated, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated = VALUES(updated), updated_by = VALUES(updated_by)',
            [$col, $id, $data, $updated, $updatedBy]
        );
    }

    /**
     * Soft-delete: turns a live doc into a tombstone (data = NULL) so
     * /api/changes can report the deletion. A no-op if the doc was never
     * live (nothing to tell pollers).
     */
    public static function delDoc(string $col, string $id, int $updated, ?string $updatedBy): void
    {
        self::run(
            'UPDATE docs SET data = NULL, updated = ?, updated_by = ?
             WHERE col = ? AND id = ? AND data IS NOT NULL',
            [$updated, $updatedBy, $col, $id]
        );
    }

    /**
     * Rows changed strictly after $sinceMs, oldest first. `data` is null
     * for deletions. Backs GET /api/changes?since=<ms>.
     */
    public static function docsSince(int $sinceMs): array
    {
        return self::all(
            'SELECT col, id, data, updated FROM docs WHERE updated > ? ORDER BY updated ASC',
            [$sinceMs]
        );
    }

    /* ---------- files ---------- */

    public static function addFile(string $id, string $name, string $type, int $size, int $created, ?string $userId): void
    {
        self::run(
            'INSERT INTO files (id, name, type, size, created, user_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $name, $type, $size, $created, $userId]
        );
    }

    public static function getFile(string $id): ?array
    {
        return self::one('SELECT * FROM files WHERE id = ?', [$id]);
    }

    public static function delFile(string $id): void
    {
        self::run('DELETE FROM files WHERE id = ?', [$id]);
    }

    /* ---------- login attempts (rate limiting) ---------- */

    public static function getLoginAttempt(string $ip): ?array
    {
        return self::one('SELECT * FROM login_attempts WHERE ip = ?', [$ip]);
    }

    public static function upsertLoginAttempt(string $ip, int $count, int $resetAt): void
    {
        self::run(
            'INSERT INTO login_attempts (ip, count, reset_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE count = VALUES(count), reset_at = VALUES(reset_at)',
            [$ip, $count, $resetAt]
        );
    }

    public static function purgeLoginAttempts(int $now): void
    {
        self::run('DELETE FROM login_attempts WHERE reset_at < ?', [$now]);
    }
}
