'use strict';
// Shared backup logic used by both the manual `npm run backup` command
// and the server's own nightly scheduler.
const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');

function runBackup({ dataDir, backupDir, keep = 14 } = {}) {
  const dir = path.resolve(dataDir || process.env.DATA_DIR || path.join(__dirname, '..', 'data'));
  const root = path.resolve(backupDir || process.env.BACKUP_DIR || path.join(dir, 'backups'));
  const stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
  const dest = path.join(root, `eventboard-${stamp}`);
  fs.mkdirSync(dest, { recursive: true });

  const db = new DatabaseSync(path.join(dir, 'eventboard.db'));
  db.exec(`VACUUM INTO '${path.join(dest, 'eventboard.db').replace(/'/g, "''")}'`);
  db.close();
  fs.cpSync(path.join(dir, 'uploads'), path.join(dest, 'uploads'), { recursive: true });

  if (keep > 0) pruneBackups(root, keep);
  return dest;
}

function pruneBackups(root, keep) {
  let entries;
  try { entries = fs.readdirSync(root).filter((n) => n.startsWith('eventboard-')).sort(); }
  catch { return; }
  for (const name of entries.slice(0, Math.max(0, entries.length - keep))) {
    fs.rmSync(path.join(root, name), { recursive: true, force: true });
  }
}

module.exports = { runBackup, pruneBackups };
