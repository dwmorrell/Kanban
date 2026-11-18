'use strict';
// Sets a new password for a login, for when nobody can sign in.
// Usage: npm run reset-password -- someone@yourvenue.com NewPassword123
const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');

const [email, password] = process.argv.slice(2);
if (!email || !password || password.length < 8) {
  console.error('Usage: npm run reset-password -- email@example.com NewPassword (at least 8 characters)');
  process.exit(1);
}
const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, '..', 'data'));
const db = new DatabaseSync(path.join(DATA_DIR, 'eventboard.db'));
const salt = crypto.randomBytes(16);
const hash = crypto.scryptSync(password, salt, 64, { N: 16384, r: 8, p: 1 });
const r = db.prepare('UPDATE users SET pass = ? WHERE email = ?')
  .run(`scrypt$${salt.toString('base64')}$${hash.toString('base64')}`, email.trim().toLowerCase());
if (!r.changes) { console.error(`No login found for ${email}.`); process.exit(1); }
db.prepare('DELETE FROM sessions WHERE user_id = (SELECT id FROM users WHERE email = ?)').run(email.trim().toLowerCase());
console.log(`Password updated for ${email}. They can sign in with it now.`);
