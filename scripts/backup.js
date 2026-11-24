'use strict';
// Makes a dated copy of the database and uploaded files in data/backups/.
// Safe to run while the board is running. The server also runs this
// automatically every night (see scheduleNightlyBackup in server.js).
const { runBackup } = require('./backup-core');

const dest = runBackup({ keep: Number(process.env.BACKUP_KEEP) || 14 });
console.log(`Backup saved to ${dest}`);
