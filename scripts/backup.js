'use strict';
// Makes a dated copy of the database and uploaded files in data/backups/.
// Safe to run while the board is running.
const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');

const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, '..', 'data'));
const stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 16);
const dest = path.join(process.env.BACKUP_DIR || path.join(DATA_DIR, 'backups'), `eventboard-${stamp}`);
fs.mkdirSync(dest, { recursive: true });

const db = new DatabaseSync(path.join(DATA_DIR, 'eventboard.db'));
db.exec(`VACUUM INTO '${path.join(dest, 'eventboard.db').replace(/'/g, "''")}'`);
db.close();
fs.cpSync(path.join(DATA_DIR, 'uploads'), path.join(dest, 'uploads'), { recursive: true });
console.log(`Backup saved to ${dest}`);
