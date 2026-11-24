'use strict';
/*
 * Minimal, dependency-free, READ-ONLY Google Sheets client.
 *
 * This file must never call anything but the Sheets API's `values.get`
 * (a GET request). It authenticates as a service account using the
 * `spreadsheets.readonly` scope, which means even a bug here could not
 * write to a sheet: Google's API rejects write calls made with a
 * read-only token before this code would ever get a chance to send one.
 * Do not add batchUpdate, values.update, values.append or similar here.
 */
const fs = require('node:fs');
const crypto = require('node:crypto');

const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';
const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

function base64url(input) {
  return Buffer.from(input).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function loadServiceAccount(keyFile) {
  const raw = fs.readFileSync(keyFile, 'utf8');
  const key = JSON.parse(raw);
  if (!key.client_email || !key.private_key) {
    throw new Error(`${keyFile} does not look like a Google service account key (missing client_email/private_key).`);
  }
  return key;
}

let cached = null; // { token, expiresAt, keyFile }

async function getAccessToken(keyFile) {
  if (cached && cached.keyFile === keyFile && cached.expiresAt > Date.now() + 60e3) return cached.token;
  const { client_email, private_key } = loadServiceAccount(keyFile);
  const now = Math.floor(Date.now() / 1000);
  const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const claims = base64url(JSON.stringify({
    iss: client_email, scope: SCOPE, aud: TOKEN_URL, iat: now, exp: now + 3600,
  }));
  const signature = crypto.createSign('RSA-SHA256').update(`${header}.${claims}`).sign(private_key);
  const assertion = `${header}.${claims}.${base64url(signature)}`;

  const res = await fetch(TOKEN_URL, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      assertion,
    }),
  });
  const body = await res.json();
  if (!res.ok) throw new Error(`Google token request failed: ${body.error_description || body.error || res.status}`);
  cached = { token: body.access_token, expiresAt: Date.now() + (body.expires_in || 3600) * 1000, keyFile };
  return cached.token;
}

/** Reads a range with GET /values/{range}. There is no write path in this file. */
async function readSheetValues({ keyFile, spreadsheetId, range }) {
  const token = await getAccessToken(keyFile);
  const url = `${SHEETS_API}/${encodeURIComponent(spreadsheetId)}/values/${encodeURIComponent(range)}?valueRenderOption=FORMATTED_VALUE`;
  const res = await fetch(url, { method: 'GET', headers: { authorization: `Bearer ${token}` } });
  const body = await res.json();
  if (!res.ok) throw new Error(`Google Sheets read failed: ${body.error?.message || res.status}`);
  return body.values || [];
}

module.exports = { readSheetValues };
