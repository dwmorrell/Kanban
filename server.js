'use strict';
/*
 * Event Board server.
 * One small Node.js process: serves the board page, keeps events, tasks and settings
 * in a SQLite file, stores uploaded files on disk, handles sign-in, and pushes live
 * updates to everyone who has the board open. No database server needed.
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');
const { runBackup } = require('./scripts/backup-core');
const { readSheetValues } = require('./scripts/google-sheets-readonly');
const { mapRowsToTasks, ID_PREFIX: WO_ID_PREFIX } = require('./scripts/workorder-sync');

loadEnv(path.join(__dirname, '.env'));

const PORT = Number(process.env.PORT) || 8080;
const HOST = process.env.HOST || '0.0.0.0';
const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, 'data'));
const UPLOAD_DIR = path.join(DATA_DIR, 'uploads');
const BASE_URL = (process.env.BASE_URL || '').replace(/\/+$/, '');
const MAX_UPLOAD = (Number(process.env.MAX_UPLOAD_MB) || 25) * 1024 * 1024;
const TRUST_PROXY = process.env.TRUST_PROXY === 'true';
const SESSION_DAYS = 30;
const COLLECTIONS = new Set(['shows', 'tasks', 'config']);
const FILE_TYPES = new Set(['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
  'text/plain', 'text/csv', 'text/markdown', 'video/mp4']);
const INDEX = path.join(__dirname, 'public', 'index.html');

fs.mkdirSync(UPLOAD_DIR, { recursive: true });

/* ---------- database ---------- */
const db = new DatabaseSync(path.join(DATA_DIR, 'eventboard.db'));
db.exec(`
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;
  CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'member', pass TEXT NOT NULL, created INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS docs (
    col TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, updated INTEGER NOT NULL,
    updated_by TEXT, PRIMARY KEY (col, id));
  CREATE TABLE IF NOT EXISTS files (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, size INTEGER NOT NULL,
    created INTEGER NOT NULL, user_id TEXT);
`);
const q = {
  countUsers: db.prepare('SELECT COUNT(*) AS n FROM users'),
  countAdmins: db.prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'"),
  userById: db.prepare('SELECT * FROM users WHERE id = ?'),
  userByEmail: db.prepare('SELECT * FROM users WHERE email = ?'),
  listUsers: db.prepare('SELECT id, email, name, role, created FROM users ORDER BY name COLLATE NOCASE'),
  addUser: db.prepare('INSERT INTO users (id, email, name, role, pass, created) VALUES (?, ?, ?, ?, ?, ?)'),
  setUserName: db.prepare('UPDATE users SET name = ? WHERE id = ?'),
  setUserRole: db.prepare('UPDATE users SET role = ? WHERE id = ?'),
  setUserPass: db.prepare('UPDATE users SET pass = ? WHERE id = ?'),
  delUser: db.prepare('DELETE FROM users WHERE id = ?'),
  addSession: db.prepare('INSERT INTO sessions (token, user_id, expires) VALUES (?, ?, ?)'),
  getSession: db.prepare('SELECT * FROM sessions WHERE token = ?'),
  touchSession: db.prepare('UPDATE sessions SET expires = ? WHERE token = ?'),
  delSession: db.prepare('DELETE FROM sessions WHERE token = ?'),
  delUserSessions: db.prepare('DELETE FROM sessions WHERE user_id = ?'),
  delUserSessionsExcept: db.prepare('DELETE FROM sessions WHERE user_id = ? AND token != ?'),
  purgeSessions: db.prepare('DELETE FROM sessions WHERE expires < ?'),
  allDocs: db.prepare('SELECT col, id, data FROM docs'),
  getDoc: db.prepare('SELECT data FROM docs WHERE col = ? AND id = ?'),
  putDoc: db.prepare(`INSERT INTO docs (col, id, data, updated, updated_by) VALUES (?, ?, ?, ?, ?)
    ON CONFLICT (col, id) DO UPDATE SET data = excluded.data, updated = excluded.updated, updated_by = excluded.updated_by`),
  delDoc: db.prepare('DELETE FROM docs WHERE col = ? AND id = ?'),
  addFile: db.prepare('INSERT INTO files (id, name, type, size, created, user_id) VALUES (?, ?, ?, ?, ?, ?)'),
  getFile: db.prepare('SELECT * FROM files WHERE id = ?'),
  delFile: db.prepare('DELETE FROM files WHERE id = ?'),
};

/* ---------- helpers ---------- */
function loadEnv(file) {
  let text;
  try { text = fs.readFileSync(file, 'utf8'); } catch { return; }
  for (const line of text.split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
  }
}
const fail = (status, code, message) => Object.assign(new Error(message), { status, code });
const validEmail = (e) => typeof e === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) && e.length <= 200;
const cleanEmail = (e) => String(e || '').trim().toLowerCase();
const newId = () => crypto.randomBytes(16).toString('hex');
const sha = (t) => crypto.createHash('sha256').update(t).digest('hex');
const publicUser = (u) => u && { id: u.id, email: u.email, name: u.name, role: u.role };

function hashPassword(pw) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(pw, salt, 64, { N: 16384, r: 8, p: 1 });
  return `scrypt$${salt.toString('base64')}$${hash.toString('base64')}`;
}
function checkPassword(pw, stored) {
  const [kind, salt, hash] = String(stored).split('$');
  if (kind !== 'scrypt' || !salt || !hash) return false;
  const want = Buffer.from(hash, 'base64');
  const got = crypto.scryptSync(String(pw), Buffer.from(salt, 'base64'), want.length, { N: 16384, r: 8, p: 1 });
  return crypto.timingSafeEqual(want, got);
}
function checkNewPassword(pw) {
  if (typeof pw !== 'string' || pw.length < 8) throw fail(400, 'weak_password', 'Passwords need at least 8 characters.');
  if (pw.length > 200) throw fail(400, 'weak_password', 'That password is too long.');
}

function send(res, status, body) {
  const json = JSON.stringify(body);
  res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
  res.end(json);
}
function readBody(req, limit) {
  return new Promise((resolve, reject) => {
    const declared = Number(req.headers['content-length'] || 0);
    if (declared > limit) { reject(fail(413, 'too_large', 'That is over the size limit.')); req.resume(); return; }
    const chunks = []; let size = 0; let over = false;
    req.on('data', (c) => { size += c.length; if (size > limit) over = true; else chunks.push(c); });
    req.on('end', () => over ? reject(fail(413, 'too_large', 'That is over the size limit.')) : resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}
async function readJson(req, limit = 1024 * 1024) {
  const buf = await readBody(req, limit);
  let data;
  try { data = JSON.parse(buf.toString('utf8') || '{}'); } catch { throw fail(400, 'invalid_argument', 'The request was not valid JSON.'); }
  if (!data || typeof data !== 'object' || Array.isArray(data)) throw fail(400, 'invalid_argument', 'Expected a JSON object.');
  return data;
}
function clientIp(req) {
  if (TRUST_PROXY) {
    const f = req.headers['cf-connecting-ip'] || String(req.headers['x-forwarded-for'] || '').split(',')[0].trim();
    if (f) return f;
  }
  return req.socket.remoteAddress || 'unknown';
}

/* ---------- sessions ---------- */
function cookies(req) {
  const out = {};
  for (const part of String(req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = part.slice(i + 1).trim();
  }
  return out;
}
function isHttps(req) {
  return process.env.COOKIE_SECURE === 'true' || (TRUST_PROXY && req.headers['x-forwarded-proto'] === 'https');
}
function startSession(req, res, userId) {
  const token = crypto.randomBytes(32).toString('base64url');
  q.addSession.run(sha(token), userId, Date.now() + SESSION_DAYS * 864e5);
  res.setHeader('set-cookie', `eb_session=${token}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${SESSION_DAYS * 86400}${isHttps(req) ? '; Secure' : ''}`);
}
function currentUser(req) {
  const token = cookies(req).eb_session;
  if (!token) return null;
  const s = q.getSession.get(sha(token));
  if (!s || s.expires < Date.now()) return null;
  if (s.expires - Date.now() < (SESSION_DAYS - 1) * 864e5) q.touchSession.run(Date.now() + SESSION_DAYS * 864e5, s.token);
  const u = q.userById.get(s.user_id);
  return u ? Object.assign(u, { sessionToken: s.token }) : null;
}
const loginAttempts = new Map();
function rateLimit(req) {
  const ip = clientIp(req), now = Date.now();
  const a = loginAttempts.get(ip) || { n: 0, reset: now + 15 * 60e3 };
  if (now > a.reset) { a.n = 0; a.reset = now + 15 * 60e3; }
  a.n++; loginAttempts.set(ip, a);
  if (a.n > 10) throw fail(429, 'rate_limited', 'Too many sign-in attempts. Wait 15 minutes and try again.');
}

/* ---------- live updates (server-sent events) ---------- */
const streams = new Set();
function broadcast(event) {
  const msg = `data: ${JSON.stringify(event)}\n\n`;
  for (const s of streams) s.res.write(msg);
}
setInterval(() => { for (const s of streams) s.res.write(': ping\n\n'); }, 25e3).unref();
setInterval(() => { q.purgeSessions.run(Date.now()); loginAttempts.clear(); }, 60 * 60e3).unref();

/* ---------- nightly backup ---------- */
function scheduleNightlyBackup() {
  const h = Number(process.env.BACKUP_HOUR);
  const hour = Number.isFinite(h) ? Math.min(23, Math.max(0, h)) : 3;
  const keep = Number(process.env.BACKUP_KEEP) || 14;
  const run = () => {
    try { console.log(`Nightly backup saved to ${runBackup({ dataDir: DATA_DIR, keep })}`); }
    catch (e) { console.error('Nightly backup failed:', e.message); }
  };
  const msUntilNext = () => {
    const next = new Date();
    next.setHours(hour, 0, 0, 0);
    if (next <= new Date()) next.setDate(next.getDate() + 1);
    return next - Date.now();
  };
  setTimeout(() => { run(); setInterval(run, 24 * 60 * 60e3).unref(); }, msUntilNext()).unref();
}
scheduleNightlyBackup();

/* ---------- work order sheet sync (read-only; never writes to Google) ---------- */
function scheduleWorkOrderSync() {
  const spreadsheetId = process.env.WORKORDER_SHEET_ID;
  if (!spreadsheetId) return; // off unless explicitly configured
  const keyFile = process.env.WORKORDER_SHEET_KEY_FILE;
  if (!keyFile) { console.error('WORKORDER_SHEET_ID is set but WORKORDER_SHEET_KEY_FILE is not; work order sync is off.'); return; }
  const range = process.env.WORKORDER_SHEET_RANGE || 'Sheet2!A1:K5000';
  const minutes = Math.max(5, Number(process.env.WORKORDER_SYNC_MINUTES) || 15);

  async function run() {
    const rows = await readSheetValues({ keyFile, spreadsheetId, range });
    const { tasks, ids } = mapRowsToTasks(rows);

    let updated = 0;
    for (const { id, task } of tasks) {
      const prevRow = q.getDoc.get('tasks', id);
      const prev = prevRow ? JSON.parse(prevRow.data) : null;
      const next = prev ? { ...prev, ...task } : { items: [], files: [], links: [], waiting: [], ...task };
      if (prev && JSON.stringify(prev) === JSON.stringify(next)) continue;
      q.putDoc.run('tasks', id, JSON.stringify(next), Date.now(), null);
      broadcast({ type: 'doc', col: 'tasks', id, data: next });
      updated++;
    }

    const stale = q.allDocs.all().filter((r) => r.col === 'tasks' && r.id.startsWith(WO_ID_PREFIX) && !ids.has(r.id));
    for (const r of stale) {
      q.delDoc.run('tasks', r.id);
      broadcast({ type: 'doc', col: 'tasks', id: r.id, deleted: true });
    }
    console.log(`Work order sheet sync: ${tasks.length} rows seen, ${updated} card(s) created/updated, ${stale.length} removed.`);
  }
  const safeRun = () => run().catch((e) => console.error('Work order sheet sync failed:', e.message));
  safeRun();
  setInterval(safeRun, minutes * 60e3).unref();
}
scheduleWorkOrderSync();

/* ---------- email (optional) ---------- */
let transport = null, mailChecked = false;
function mailer() {
  if (mailChecked) return transport;
  mailChecked = true;
  if (!process.env.SMTP_HOST) return null;
  try {
    const nodemailer = require('nodemailer');
    const port = Number(process.env.SMTP_PORT) || 587;
    transport = nodemailer.createTransport({
      host: process.env.SMTP_HOST, port, secure: port === 465,
      auth: process.env.SMTP_USER ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS } : undefined,
    });
  } catch (e) {
    console.warn('Email alerts are off: the nodemailer package is missing. Run "npm install" and restart.');
  }
  return transport;
}
async function sendMail(to, subject, text) {
  const t = mailer();
  if (!t) return false;
  await t.sendMail({ from: process.env.SMTP_FROM || process.env.SMTP_USER, to, subject, text });
  return true;
}
const skipMail = (email, actor) => !validEmail(email) || email.endsWith('@example.com') || email === actor.email;
async function notifyChanges(col, id, before, after, actor) {
  if (!mailer()) return;
  const kind = col === 'tasks' ? 'task' : 'event';
  const title = after.title || `Untitled ${kind}`;
  const who = actor.name || actor.email;
  const link = BASE_URL ? `\n\nOpen the card: ${BASE_URL}/#card-${id}` : '';
  const known = new Set(((before && before.waiting) || []).map((w) => w && w.id));
  for (const w of Array.isArray(after.waiting) ? after.waiting : []) {
    if (!w || w.done || known.has(w.id)) continue;
    const to = cleanEmail(w.email);
    if (skipMail(to, actor)) continue;
    await sendMail(to, `${who} is waiting on you: ${title}`,
      `${who} is waiting on you for the ${kind} "${title}" on Event Board.\n\nWhat they need: ${w.what || 'Your input'}${link}\n\nWhen it's handled, tick it off on the card so they know.`);
  }
  if (col === 'tasks') {
    const to = cleanEmail(after.assignee);
    if (to && to !== cleanEmail(before && before.assignee) && !skipMail(to, actor)) {
      const due = after.due ? `\nDue: ${after.due}` : '';
      const notes = after.notes ? `\n\nNotes:\n${String(after.notes).slice(0, 1000)}` : '';
      await sendMail(to, `${who} assigned you a task: ${title}`, `${who} assigned you "${title}" on Event Board.${due}${notes}${link}`);
    }
  }
}

/* ---------- routes ---------- */
const routes = [];
function route(method, pattern, handler, opts = {}) {
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$');
  routes.push({ method, re, handler, opts });
}
const userPayload = () => q.listUsers.all().map((u) => ({ id: u.id, email: u.email, name: u.name, role: u.role }));

route('GET', '/api/status', async (req, res) => {
  const u = currentUser(req);
  send(res, 200, { setup: q.countUsers.get().n === 0, user: publicUser(u), mail: !!process.env.SMTP_HOST });
});

route('POST', '/api/setup', async (req, res) => {
  if (q.countUsers.get().n > 0) throw fail(409, 'already_setup', 'The board already has an admin. Sign in instead.');
  const b = await readJson(req);
  const email = cleanEmail(b.email);
  if (!validEmail(email)) throw fail(400, 'invalid_email', 'Enter a valid email address.');
  checkNewPassword(b.password);
  const id = newId();
  q.addUser.run(id, email, String(b.name || '').trim().slice(0, 80) || email, 'admin', hashPassword(b.password), Date.now());
  startSession(req, res, id);
  send(res, 200, { user: publicUser(q.userById.get(id)) });
});

route('POST', '/api/login', async (req, res) => {
  rateLimit(req);
  const b = await readJson(req);
  const u = q.userByEmail.get(cleanEmail(b.email));
  if (!u || !checkPassword(String(b.password || ''), u.pass)) throw fail(401, 'bad_login', 'Wrong email or password.');
  startSession(req, res, u.id);
  send(res, 200, { user: publicUser(u) });
});

route('POST', '/api/logout', async (req, res) => {
  const token = cookies(req).eb_session;
  if (token) q.delSession.run(sha(token));
  res.setHeader('set-cookie', 'eb_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
  send(res, 200, { ok: true });
});

route('GET', '/api/state', async (req, res, p, u) => {
  const docs = { shows: {}, tasks: {}, config: {} };
  for (const row of q.allDocs.all()) if (docs[row.col]) docs[row.col][row.id] = JSON.parse(row.data);
  send(res, 200, { docs, users: userPayload(), me: publicUser(u), mail: !!process.env.SMTP_HOST });
}, { auth: 'user' });

route('GET', '/api/events', async (req, res, p, u) => {
  res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store', connection: 'keep-alive', 'x-accel-buffering': 'no' });
  res.write('retry: 3000\n\n');
  const s = { res, userId: u.id };
  streams.add(s);
  req.on('close', () => streams.delete(s));
}, { auth: 'user' });

function checkDocPath(col, id) {
  if (!COLLECTIONS.has(col)) throw fail(404, 'not_found', 'Unknown collection.');
  if (!/^[A-Za-z0-9_.\-]{1,100}$/.test(id)) throw fail(400, 'invalid_argument', 'Invalid card id.');
}
route('PUT', '/api/docs/:col/:id', async (req, res, { col, id }, u) => {
  checkDocPath(col, id);
  const data = await readJson(req, 600 * 1024);
  const prevRow = q.getDoc.get(col, id);
  const before = prevRow ? JSON.parse(prevRow.data) : null;
  q.putDoc.run(col, id, JSON.stringify(data), Date.now(), u.id);
  broadcast({ type: 'doc', col, id, data });
  send(res, 200, { ok: true });
  if (col !== 'config') notifyChanges(col, id, before, data, u).catch((e) => console.error('Email failed:', e.message));
}, { auth: 'user' });

route('DELETE', '/api/docs/:col/:id', async (req, res, { col, id }) => {
  checkDocPath(col, id);
  q.delDoc.run(col, id);
  broadcast({ type: 'doc', col, id, deleted: true });
  send(res, 200, { ok: true });
}, { auth: 'user' });

route('POST', '/api/files', async (req, res, p, u) => {
  const type = String(req.headers['content-type'] || '').split(';')[0].trim().toLowerCase();
  if (!FILE_TYPES.has(type)) throw fail(415, 'unsupported_type', 'That file type is not allowed. Use PDF, images, text or MP4.');
  let name = 'file';
  try { name = decodeURIComponent(String(req.headers['x-filename'] || 'file')); } catch { /* keep default */ }
  name = name.replace(/[\\/\r\n"\x00-\x1f]/g, '_').slice(0, 200) || 'file';
  const buf = await readBody(req, MAX_UPLOAD);
  if (!buf.length) throw fail(400, 'invalid_request', 'That file is empty.');
  const id = newId();
  fs.writeFileSync(path.join(UPLOAD_DIR, id), buf);
  q.addFile.run(id, name, type, buf.length, Date.now(), u.id);
  send(res, 200, { id, url: `/_blob/${id}`, sizeBytes: buf.length, contentType: type });
}, { auth: 'user' });

route('GET', '/_blob/:id', async (req, res, { id }) => {
  const f = /^[0-9a-f]{32}$/.test(id) && q.getFile.get(id);
  const file = f && path.join(UPLOAD_DIR, f.id);
  if (!f || !fs.existsSync(file)) throw fail(404, 'not_found', 'That file is no longer here.');
  res.writeHead(200, {
    'content-type': f.type, 'content-length': fs.statSync(file).size,
    'content-disposition': `inline; filename*=UTF-8''${encodeURIComponent(f.name)}`,
    'cache-control': 'private, max-age=86400',
  });
  fs.createReadStream(file).pipe(res);
}, { auth: 'user' });

route('DELETE', '/api/files/:id', async (req, res, { id }) => {
  if (!/^[0-9a-f]{32}$/.test(id)) throw fail(400, 'invalid_request', 'Invalid file id.');
  const f = q.getFile.get(id);
  if (f) { q.delFile.run(id); fs.rmSync(path.join(UPLOAD_DIR, id), { force: true }); }
  send(res, 200, { deleted: !!f });
}, { auth: 'user' });

/* your own account */
route('PATCH', '/api/me', async (req, res, p, u) => {
  const b = await readJson(req);
  if (typeof b.name === 'string') q.setUserName.run(b.name.trim().slice(0, 80) || u.email, u.id);
  broadcast({ type: 'users', users: userPayload() });
  send(res, 200, { user: publicUser(q.userById.get(u.id)) });
}, { auth: 'user' });

route('POST', '/api/password', async (req, res, p, u) => {
  rateLimit(req);
  const b = await readJson(req);
  if (!checkPassword(String(b.current || ''), u.pass)) throw fail(400, 'bad_password', 'Your current password is wrong.');
  checkNewPassword(b.next);
  q.setUserPass.run(hashPassword(b.next), u.id);
  q.delUserSessionsExcept.run(u.id, u.sessionToken);
  send(res, 200, { ok: true });
}, { auth: 'user' });

/* admin: logins */
route('POST', '/api/users', async (req, res) => {
  const b = await readJson(req);
  const email = cleanEmail(b.email);
  if (!validEmail(email)) throw fail(400, 'invalid_email', 'Enter a valid email address.');
  if (q.userByEmail.get(email)) throw fail(409, 'exists', `${email} already has a login.`);
  checkNewPassword(b.password);
  const role = b.role === 'admin' ? 'admin' : 'member';
  q.addUser.run(newId(), email, String(b.name || '').trim().slice(0, 80) || email, role, hashPassword(b.password), Date.now());
  broadcast({ type: 'users', users: userPayload() });
  send(res, 200, { users: userPayload() });
}, { auth: 'admin' });

route('PATCH', '/api/users/:id', async (req, res, { id }, me) => {
  const target = q.userById.get(id);
  if (!target) throw fail(404, 'not_found', 'That login no longer exists.');
  const b = await readJson(req);
  if (typeof b.name === 'string') q.setUserName.run(b.name.trim().slice(0, 80) || target.email, id);
  if (b.role === 'admin' || b.role === 'member') {
    if (b.role === 'member' && target.role === 'admin' && q.countAdmins.get().n <= 1) throw fail(400, 'last_admin', 'The board needs at least one admin.');
    q.setUserRole.run(b.role, id);
  }
  if (b.password !== undefined) {
    checkNewPassword(b.password);
    q.setUserPass.run(hashPassword(b.password), id);
    if (id !== me.id) q.delUserSessions.run(id);
  }
  broadcast({ type: 'users', users: userPayload() });
  send(res, 200, { users: userPayload() });
}, { auth: 'admin' });

route('DELETE', '/api/users/:id', async (req, res, { id }, me) => {
  if (id === me.id) throw fail(400, 'self', "You can't remove your own login.");
  const target = q.userById.get(id);
  if (target) {
    q.delUser.run(id);
    for (const s of streams) if (s.userId === id) { s.res.end(); streams.delete(s); }
  }
  broadcast({ type: 'users', users: userPayload() });
  send(res, 200, { users: userPayload() });
}, { auth: 'admin' });

route('POST', '/api/test-email', async (req, res, p, u) => {
  if (!mailer()) throw fail(400, 'mail_off', 'Email is not set up. Add the SMTP settings to the .env file and restart.');
  try { await sendMail(u.email, 'Event Board test email', 'Email alerts from Event Board are working.'); }
  catch (e) { throw fail(502, 'mail_failed', `The email could not be sent. The mail server said: ${e.message}`); }
  send(res, 200, { ok: true, to: u.email });
}, { auth: 'admin' });

/* ---------- server ---------- */
const server = http.createServer(async (req, res) => {
  res.setHeader('x-content-type-options', 'nosniff');
  res.setHeader('referrer-policy', 'same-origin');
  res.setHeader('x-frame-options', 'DENY');
  try {
    const url = new URL(req.url, 'http://localhost');
    const p = url.pathname;
    if ((req.method === 'GET' || req.method === 'HEAD') && (p === '/' || p === '/index.html')) {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-cache' });
      return req.method === 'HEAD' ? res.end() : fs.createReadStream(INDEX).pipe(res);
    }
    if (p === '/healthz') { res.writeHead(200, { 'content-type': 'text/plain' }); return res.end('ok'); }
    if (p.startsWith('/api/') && req.method !== 'GET' && req.headers['x-requested-with'] !== 'eventboard') {
      throw fail(403, 'forbidden', 'Request blocked.');
    }
    for (const r of routes) {
      if (r.method !== req.method) continue;
      const m = p.match(r.re);
      if (!m) continue;
      let user = null;
      if (r.opts.auth) {
        user = currentUser(req);
        if (!user) throw fail(401, 'signed_out', 'Please sign in again.');
        if (r.opts.auth === 'admin' && user.role !== 'admin') throw fail(403, 'forbidden', 'Only admins can do that.');
      }
      const params = {};
      for (const [k, v] of Object.entries(m.groups || {})) {
        try { params[k] = decodeURIComponent(v); } catch { throw fail(400, 'invalid_argument', 'Bad address.'); }
      }
      return await r.handler(req, res, params, user);
    }
    throw fail(404, 'not_found', 'Not found.');
  } catch (e) {
    const status = e.status || 500;
    if (status === 500) console.error(e);
    if (!res.headersSent) send(res, status, { error: status === 500 ? 'Something went wrong on the server.' : e.message, code: e.code || 'error' });
    else res.end();
  }
});

server.listen(PORT, HOST, () => {
  console.log(`Event Board is running on http://localhost:${PORT}`);
  console.log(`Data folder: ${DATA_DIR}`);
  if (q.countUsers.get().n === 0) console.log('No logins yet: open the board in a browser to create the first admin account.');
  console.log(process.env.SMTP_HOST ? 'Email alerts: on' : 'Email alerts: off (add SMTP settings to .env to turn them on)');
});
function shutdown() { server.close(); for (const s of streams) s.res.end(); db.close(); process.exit(0); }
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
