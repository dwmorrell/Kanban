'use strict';
// Maps rows from the "Work Order Tracking" Google Sheet onto Event Board task
// cards. Pure functions only (no network, no database) so the mapping can be
// unit tested without real Google credentials.

const ID_PREFIX = 'wo-';
const HEADERS = ['Status', 'Item needing repair', 'report date', 'Where', 'Priority',
  'Created by', 'Assigned to', 'End date', 'Notes', 'Deliverable', 'Closing Notes'];

const STATUS_TO_COL = {
  'not started': 'backlog',
  'kicking the can down the road': 'backlog',
  'in progress': 'doing',
  'blocked': 'doing',
  'completed': 'done',
  'canceled': 'done',
};
const PRIORITY_RANK = { p0: 0, p1: 1, p2: 2, p3: 3 };
const ROOM_ALIASES = { reelworks: 'Reelworks', tracks: 'Tracks', lounge: 'Lounge', basement: 'Basement' };

function parseDate(s) {
  const m = String(s || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (!m) return '';
  const [, mo, d, y] = m;
  return `${y}-${mo.padStart(2, '0')}-${d.padStart(2, '0')}`;
}
function dateMs(iso) {
  if (!iso) return 0;
  const t = Date.parse(iso);
  return Number.isFinite(t) ? t : 0;
}
function normalizeRoom(where) {
  const key = String(where || '').trim().toLowerCase();
  return ROOM_ALIASES[key] || String(where || '').trim();
}

/** Checks the sheet's header row still matches what this mapping expects. */
function headersLookRight(headerRow) {
  if (!Array.isArray(headerRow) || headerRow.length < HEADERS.length) return false;
  return HEADERS.every((h, i) => String(headerRow[i] || '').trim().toLowerCase() === h.toLowerCase());
}

/**
 * rows: the full values.get() result, including the header row as rows[0].
 * Returns { tasks: [{ id, task }], ids: Set<string> } — ids is every id this
 * sync run produced, so the caller can remove cards for rows that vanished.
 */
function mapRowsToTasks(rows) {
  if (!headersLookRight(rows[0])) {
    throw new Error('Work order sheet headers changed or are missing; refusing to sync until checked.');
  }
  const parsed = [];
  for (let i = 1; i < rows.length; i++) {
    const row = rows[i];
    if (!row || !row.some((c) => String(c || '').trim())) continue; // skip blank rows
    const [status, item, reportDate, where, priority, createdBy, assignedTo, endDate, notes, deliverable, closingNotes] = row;
    const rowNumber = i + 1; // 1-indexed, matches the row number shown in Sheets
    const statusKey = String(status || '').trim().toLowerCase();
    const col = STATUS_TO_COL[statusKey] || 'backlog';
    const reportDateIso = parseDate(reportDate);
    const due = parseDate(endDate) || reportDateIso;
    const created = dateMs(reportDateIso);

    const noteLines = [`Imported from the Work Order Tracking sheet, row ${rowNumber}.`];
    if (createdBy) noteLines.push(`Logged by ${createdBy}${reportDateIso ? ` on ${reportDateIso}` : ''}.`);
    if (statusKey === 'blocked') noteLines.push('Status in sheet: Blocked.');
    if (statusKey === 'canceled') noteLines.push('Canceled in the sheet (not completed).');
    if (deliverable) noteLines.push(`Deliverable on file in the sheet: ${deliverable}.`);
    if (notes) noteLines.push('', String(notes).trim());
    if (closingNotes) noteLines.push('', `Closing notes: ${String(closingNotes).trim()}`);

    parsed.push({
      id: `${ID_PREFIX}${rowNumber}`,
      col,
      priorityRank: PRIORITY_RANK[String(priority || '').trim().toLowerCase()] ?? 4,
      rowNumber,
      // Deliberately does not include items/files/links/waiting: those are
      // board-managed (checklists, uploads, mentions) and must survive a
      // resync untouched. See the merge in scheduleWorkOrderSync.
      task: {
        title: String(item || '').trim() || `Work order, row ${rowNumber}`,
        col,
        assignee: String(assignedTo || '').trim(),
        due,
        room: normalizeRoom(where),
        notes: noteLines.join('\n'),
        doneAt: col === 'done' ? (due ? dateMs(due) : created) : 0,
        created,
      },
    });
  }

  // Rank within each column: lower priority number first, then sheet order.
  const byCol = new Map();
  for (const p of parsed) {
    if (!byCol.has(p.col)) byCol.set(p.col, []);
    byCol.get(p.col).push(p);
  }
  for (const list of byCol.values()) {
    list.sort((a, b) => a.priorityRank - b.priorityRank || a.rowNumber - b.rowNumber);
    list.forEach((p, idx) => { p.task.rank = idx; });
  }

  return { tasks: parsed.map((p) => ({ id: p.id, task: p.task })), ids: new Set(parsed.map((p) => p.id)) };
}

module.exports = { mapRowsToTasks, headersLookRight, parseDate, ID_PREFIX, HEADERS };
