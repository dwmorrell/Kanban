<?php
/**
 * Maps rows from the "Work Order Tracking" Google Sheet onto Event Board
 * task cards. Pure functions only (no network, no database) — ports
 * scripts/workorder-sync.js (archive/node-event-board) 1:1. cron/workorder_sync.php
 * is the thin entry point that wires this against the real DB and
 * lib/apps_script_client.php.
 *
 * PHP-specific gotcha this file is written around (see
 * docs/PHP_REWRITE_PLAN.md): the JS version groups parsed rows by column,
 * sorts each group by priority, then assigns `rank` by mutating each row
 * object in place — this works in JS because objects are reference types,
 * so the same object sitting in the flat `parsed` array also sees the
 * mutation. PHP arrays are copy-on-write, not references, so the same
 * approach would silently reset every row's rank if the grouped-by-column
 * list were built from copies. Instead, $byCol below groups *indices* into
 * the single canonical $parsed array, sorts those indices, and writes the
 * rank straight back onto $parsed[$idx] — there is only ever one array
 * holding each row, so there is nothing for the mutation to fail to reach.
 */
declare(strict_types=1);

const WORKORDER_ID_PREFIX = 'wo-';

const WORKORDER_HEADERS = [
    'Status', 'Item needing repair', 'report date', 'Where', 'Priority',
    'Created by', 'Assigned to', 'End date', 'Notes', 'Deliverable', 'Closing Notes',
];

const WORKORDER_STATUS_TO_COL = [
    'not started' => 'backlog',
    'kicking the can down the road' => 'backlog',
    'in progress' => 'doing',
    'blocked' => 'doing',
    'completed' => 'done',
    'canceled' => 'done',
];

const WORKORDER_PRIORITY_RANK = ['p0' => 0, 'p1' => 1, 'p2' => 2, 'p3' => 3];

const WORKORDER_ROOM_ALIASES = [
    'reelworks' => 'Reelworks',
    'tracks' => 'Tracks',
    'lounge' => 'Lounge',
    'basement' => 'Basement',
];

/** 'M/D/YYYY' (sheet display format) -> 'YYYY-MM-DD', or '' if it doesn't match. */
function workorder_parse_date(mixed $s): string
{
    $s = trim((string) $s);
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
        return '';
    }
    return sprintf('%s-%02d-%02d', $m[3], (int) $m[1], (int) $m[2]);
}

/** 'YYYY-MM-DD' -> epoch-ms (UTC midnight), or 0 if $iso is blank/unparseable. */
function workorder_date_ms(string $iso): int
{
    if ($iso === '') {
        return 0;
    }
    $t = strtotime($iso . 'T00:00:00+00:00');
    return $t === false ? 0 : $t * 1000;
}

function workorder_normalize_room(mixed $where): string
{
    $raw = trim((string) $where);
    $key = strtolower($raw);
    return WORKORDER_ROOM_ALIASES[$key] ?? $raw;
}

/** Checks the sheet's header row still matches what this mapping expects. */
function workorder_headers_look_right(mixed $headerRow): bool
{
    if (!is_array($headerRow) || count($headerRow) < count(WORKORDER_HEADERS)) {
        return false;
    }
    foreach (WORKORDER_HEADERS as $i => $h) {
        if (strtolower(trim((string) ($headerRow[$i] ?? ''))) !== strtolower($h)) {
            return false;
        }
    }
    return true;
}

/**
 * $rows is the full values.get()-shaped result, including the header row
 * as $rows[0].
 *
 * @return array{tasks: list<array{id: string, task: array<string, mixed>}>, ids: array<string, true>}
 *     `ids` is a set (only the keys matter) of every id this sync run
 *     produced, so the caller can remove cards for rows that have since
 *     vanished from the sheet.
 * @throws RuntimeException if the header row doesn't match WORKORDER_HEADERS —
 *     refuses to sync rather than risk misreading a reshuffled sheet.
 */
function workorder_map_rows_to_tasks(array $rows): array
{
    if (!workorder_headers_look_right($rows[0] ?? null)) {
        throw new RuntimeException('Work order sheet headers changed or are missing; refusing to sync until checked.');
    }

    $parsed = [];
    $count = count($rows);
    for ($i = 1; $i < $count; $i++) {
        $row = $rows[$i] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $hasContent = false;
        foreach ($row as $c) {
            if (trim((string) $c) !== '') {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) {
            continue; // skip blank rows
        }

        $status = $row[0] ?? '';
        $item = $row[1] ?? '';
        $reportDate = $row[2] ?? '';
        $where = $row[3] ?? '';
        $priority = $row[4] ?? '';
        $createdBy = (string) ($row[5] ?? '');
        $assignedTo = $row[6] ?? '';
        $endDate = $row[7] ?? '';
        $notes = (string) ($row[8] ?? '');
        $deliverable = (string) ($row[9] ?? '');
        $closingNotes = (string) ($row[10] ?? '');

        $rowNumber = $i + 1; // 1-indexed, matches the row number shown in Sheets
        $statusKey = strtolower(trim((string) $status));
        $col = WORKORDER_STATUS_TO_COL[$statusKey] ?? 'backlog';
        $reportDateIso = workorder_parse_date($reportDate);
        $due = workorder_parse_date($endDate) ?: $reportDateIso;
        $created = workorder_date_ms($reportDateIso);

        // Note: these checks are against '' specifically, not PHP truthiness —
        // "0" is a non-empty, meaningful cell value but PHP's `if ($x)` would
        // treat the string "0" as false, unlike JS's `if (x)` on the same value.
        $noteLines = ["Imported from the Work Order Tracking sheet, row $rowNumber."];
        if ($createdBy !== '') {
            $noteLines[] = "Logged by $createdBy" . ($reportDateIso !== '' ? " on $reportDateIso" : '') . '.';
        }
        if ($statusKey === 'blocked') {
            $noteLines[] = 'Status in sheet: Blocked.';
        }
        if ($statusKey === 'canceled') {
            $noteLines[] = 'Canceled in the sheet (not completed).';
        }
        if ($deliverable !== '') {
            $noteLines[] = "Deliverable on file in the sheet: $deliverable.";
        }
        if ($notes !== '') {
            $noteLines[] = '';
            $noteLines[] = trim($notes);
        }
        if ($closingNotes !== '') {
            $noteLines[] = '';
            $noteLines[] = 'Closing notes: ' . trim($closingNotes);
        }

        $title = trim((string) $item);
        $assignee = trim((string) $assignedTo);

        $parsed[] = [
            'id' => WORKORDER_ID_PREFIX . $rowNumber,
            'col' => $col,
            'priorityRank' => WORKORDER_PRIORITY_RANK[strtolower(trim((string) $priority))] ?? 4,
            'rowNumber' => $rowNumber,
            // Deliberately does not include items/files/links/waiting: those
            // are board-managed (checklists, uploads, mentions) and must
            // survive a resync untouched — see the merge in
            // cron/workorder_sync.php.
            'task' => [
                'title' => $title !== '' ? $title : "Work order, row $rowNumber",
                'col' => $col,
                'assignee' => $assignee,
                'due' => $due,
                'room' => workorder_normalize_room($where),
                'notes' => implode("\n", $noteLines),
                'doneAt' => $col === 'done' ? ($due !== '' ? workorder_date_ms($due) : $created) : 0,
                'created' => $created,
            ],
        ];
    }

    // Rank within each column: lower priority number first, then sheet
    // order. See the file header on why this groups *indices* into $parsed
    // rather than copies of its rows.
    $byCol = [];
    foreach ($parsed as $idx => $p) {
        $byCol[$p['col']][] = $idx;
    }
    foreach ($byCol as $indices) {
        usort($indices, static function (int $a, int $b) use ($parsed): int {
            return ($parsed[$a]['priorityRank'] <=> $parsed[$b]['priorityRank'])
                ?: ($parsed[$a]['rowNumber'] <=> $parsed[$b]['rowNumber']);
        });
        foreach ($indices as $rank => $idx) {
            $parsed[$idx]['task']['rank'] = $rank;
        }
    }

    $tasks = [];
    $ids = [];
    foreach ($parsed as $p) {
        $tasks[] = ['id' => $p['id'], 'task' => $p['task']];
        $ids[$p['id']] = true;
    }
    return ['tasks' => $tasks, 'ids' => $ids];
}
