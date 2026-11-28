<?php
/**
 * cron/workorder_sync.php — polls the "Work Order Tracking" Google Sheet
 * (via a Google Apps Script Web App) and upserts/removes `tasks` cards to
 * match it. CLI only (cPanel Cron Jobs or a manual
 * `php cron/workorder_sync.php`); off entirely unless WORKORDER_APPS_SCRIPT_URL
 * is defined in config.php.
 *
 * Ports server.js's scheduleWorkOrderSync()'s run() (archive/node-event-board)
 * 1:1: lib/workorder_mapping.php (the pure row->task mapping, including the
 * per-column rank assignment) and lib/apps_script_client.php (the read-only
 * HTTP fetch) do the actual work; this file is just the CLI wiring and the
 * DB merge/orphan-delete loop server.js had inline.
 *
 * Merge rule (same as the Node version, already validated there): sheet-
 * owned fields (title/col/assignee/due/room/notes/doneAt/created/rank) are
 * overwritten on every sync; board-owned fields (items/files/links/waiting)
 * are preserved untouched on an update and only defaulted to [] the first
 * time a card is created, since workorder_map_rows_to_tasks() deliberately
 * never includes them.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/workorder_mapping.php';
require_once __DIR__ . '/../lib/apps_script_client.php';

$url = defined('WORKORDER_APPS_SCRIPT_URL') ? (string) WORKORDER_APPS_SCRIPT_URL : '';
if ($url === '') {
    echo "Work order sheet sync is not configured (WORKORDER_APPS_SCRIPT_URL unset); nothing to do.\n";
    exit(0);
}
$token = defined('WORKORDER_APPS_SCRIPT_TOKEN') ? (string) WORKORDER_APPS_SCRIPT_TOKEN : '';
if ($token === '') {
    fwrite(STDERR, "WORKORDER_APPS_SCRIPT_URL is set but WORKORDER_APPS_SCRIPT_TOKEN is not; work order sync is off.\n");
    exit(1);
}
$sheet = defined('WORKORDER_SHEET_NAME') ? (string) WORKORDER_SHEET_NAME : null;
$range = defined('WORKORDER_SHEET_RANGE') ? (string) WORKORDER_SHEET_RANGE : null;

try {
    $rows = workorder_read_sheet_values($url, $token, $sheet, $range);
    $result = workorder_map_rows_to_tasks($rows);
} catch (Throwable $e) {
    fwrite(STDERR, 'Work order sheet sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$tasks = $result['tasks'];
$ids = $result['ids'];

$updated = 0;
foreach ($tasks as $entry) {
    $id = $entry['id'];
    $task = $entry['task'];
    $prevRaw = Q::getDoc('tasks', $id);
    $prev = $prevRaw !== null ? json_decode($prevRaw, true) : null;
    $next = is_array($prev)
        ? array_merge($prev, $task)
        : array_merge(['items' => [], 'files' => [], 'links' => [], 'waiting' => []], $task);
    if (is_array($prev) && json_encode($prev) === json_encode($next)) {
        continue; // nothing actually changed; don't bump `updated`/mint a seq for a no-op write
    }
    Q::putDoc('tasks', $id, json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now_ms(), null);
    $updated++;
}

$stale = [];
foreach (Q::allDocs() as $row) {
    if ($row['col'] === 'tasks' && str_starts_with($row['id'], WORKORDER_ID_PREFIX) && !isset($ids[$row['id']])) {
        $stale[] = $row['id'];
    }
}
foreach ($stale as $id) {
    Q::delDoc('tasks', $id, now_ms(), null);
}

printf(
    "Work order sheet sync: %d row(s) seen, %d card(s) created/updated, %d removed.\n",
    count($tasks),
    $updated,
    count($stale)
);
