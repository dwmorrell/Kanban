<?php
/**
 * cron/backup.php — makes a dated copy of the database and uploaded files
 * under private/backups/, then prunes old backups and purges old
 * tombstones. CLI only (cPanel Cron Jobs or a manual `php cron/backup.php`).
 *
 * Ports scripts/backup-core.js's runBackup()/pruneBackups() (archive/
 * node-event-board) to MySQL. The original shells out to nothing — it uses
 * SQLite's own VACUUM INTO. There is no MySQL equivalent available without
 * exec()/shell_exec() (mysqldump), which budget shared hosting frequently
 * disables outright, so this dumps data the same way lib/db.php's Q class
 * talks to the DB: plain PDO queries.
 *
 * This is a *data-only* dump (DELETE FROM table; then batched INSERTs built
 * from `SELECT *`, each value escaped with PDO::quote() — never
 * addslashes(), which is charset-unaware). The schema itself is NOT dumped
 * here; lib/db.php's ensure_schema() (run automatically on first DB
 * connection) is the schema source of truth. Restoring a backup means
 * pointing a fresh DB at this app once (so ensure_schema() runs) and then
 * importing dump.sql — not "the dump recreates its own schema."
 *
 * Table order matters here: `sessions.user_id` has a FOREIGN KEY on
 * `users.id`, so users must be fully (re)inserted before sessions' inserts
 * run, or a restore would fail with a FK violation. `doc_seq` must also be
 * dumped (not just docs) — its only content is the AUTO_INCREMENT ticker's
 * row count, but docs.seq values are minted from it, and MySQL auto-adjusts
 * an AUTO_INCREMENT column's next value to one past the highest explicit
 * value it's ever been given an INSERT for. Skipping doc_seq here would let
 * a post-restore mintSeq() hand out a seq number that collides with one a
 * restored docs row already has, breaking /api/changes' cursor invariant.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../lib/auth.php';

/** Dependency order: a table must come after every table its rows' INSERTs depend on via FK. */
const BACKUP_TABLES = ['users', 'sessions', 'doc_seq', 'docs', 'files', 'login_attempts'];
const BACKUP_TOMBSTONE_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
const BACKUP_INSERT_BATCH_SIZE = 200;

function backup_dir(): string
{
    $dir = defined('BACKUP_DIR') ? BACKUP_DIR : (__DIR__ . '/../private/backups');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create backup directory: $dir");
    }
    return $dir;
}

function backup_keep(): int
{
    return defined('BACKUP_KEEP') ? (int) BACKUP_KEEP : 14;
}

/**
 * Writes "DELETE FROM `$table`;" followed by batched INSERTs for every row
 * currently in it, straight to $fh (streamed, not buffered in memory, so
 * this stays cheap even if a table grows large over time).
 */
function backup_dump_table(PDO $pdo, string $table, mixed $fh): void
{
    fwrite($fh, "DELETE FROM `$table`;\n");

    $stmt = $pdo->query("SELECT * FROM `$table`");
    $columns = null;
    $batch = [];
    $flush = static function () use (&$batch, &$columns, $table, $fh): void {
        if ($batch === []) {
            return;
        }
        $columnList = '`' . implode('`, `', $columns) . '`';
        fwrite($fh, "INSERT INTO `$table` ($columnList) VALUES " . implode(', ', $batch) . ";\n");
        $batch = [];
    };

    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        if ($columns === null) {
            $columns = array_keys($row);
        }
        $values = array_map(
            // PDO::quote(null) is not reliable across drivers (some return
            // the string "''" rather than erroring), which would store an
            // empty string instead of SQL NULL — a real difference for a
            // nullable column like docs.data (NULL means "tombstone") or
            // sessions/files' nullable user references. NULL is therefore
            // handled explicitly, never passed through quote().
            static fn (mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
            array_values($row)
        );
        $batch[] = '(' . implode(', ', $values) . ')';
        if (count($batch) >= BACKUP_INSERT_BATCH_SIZE) {
            $flush();
        }
    }
    $flush();
}

/** Recursively copies private/uploads/ into $dest. No shell dependency (RecursiveDirectoryIterator only). */
function backup_copy_uploads(string $dest): void
{
    $src = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../private/uploads');
    if (!is_dir($src)) {
        return; // nothing uploaded yet
    }
    mkdir($dest, 0755, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $target = $dest . '/' . $it->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function backup_remove_dir_recursive(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Removes the oldest backups beyond $keep. Directory names are
 * `eventboard-YYYY-MM-DD-HH-ii-ss` (zero-padded), which sorts
 * lexicographically identically to chronologically, so a plain sort()
 * reproduces the same ordering scripts/backup-core.js's pruneBackups() gets
 * from Node's default string sort.
 */
function backup_prune(string $root, int $keep): void
{
    if ($keep <= 0) {
        return;
    }
    $entries = [];
    foreach (scandir($root) ?: [] as $name) {
        if (str_starts_with($name, 'eventboard-') && is_dir("$root/$name")) {
            $entries[] = $name;
        }
    }
    sort($entries);
    $excess = count($entries) - $keep;
    for ($i = 0; $i < $excess; $i++) {
        backup_remove_dir_recursive("$root/{$entries[$i]}");
    }
}

$pdo = db();
$root = backup_dir();
$stamp = date('Y-m-d-H-i-s'); // UTC — lib/db.php sets date_default_timezone_set('UTC').
$dest = $root . '/eventboard-' . $stamp;
mkdir($dest, 0755, true);

$fh = fopen($dest . '/dump.sql', 'w');
if ($fh === false) {
    fwrite(STDERR, "Could not open dump.sql for writing in $dest\n");
    exit(1);
}
try {
    foreach (BACKUP_TABLES as $table) {
        backup_dump_table($pdo, $table, $fh);
    }
} finally {
    fclose($fh);
}

backup_copy_uploads($dest . '/uploads');
backup_prune($root, backup_keep());

$purged = Q::purgeTombstones(now_ms() - BACKUP_TOMBSTONE_MAX_AGE_MS);

echo "Backup saved to $dest ($purged old tombstone" . ($purged === 1 ? '' : 's') . " purged)\n";
