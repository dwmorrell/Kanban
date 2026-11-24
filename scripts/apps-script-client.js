'use strict';
/*
 * Reads the work order sheet via a Google Apps Script Web App endpoint
 * (see scripts/apps-script/workorder-reader.gs) instead of Google's Sheets
 * API, so no Google Cloud project, service account or billing is needed.
 *
 * This file only ever makes a GET request. The Apps Script side enforces
 * read-only access by only calling getDisplayValues(); this client has no
 * way to ask it to write anything even if it wanted to.
 */

async function readSheetValues({ url, token, sheet, range }) {
  if (!url) throw new Error('WORKORDER_APPS_SCRIPT_URL is not set.');
  if (!token) throw new Error('WORKORDER_APPS_SCRIPT_TOKEN is not set.');
  const q = new URLSearchParams({ token });
  if (sheet) q.set('sheet', sheet);
  if (range) q.set('range', range);
  const res = await fetch(`${url}?${q}`, { method: 'GET' });
  const body = await res.json().catch(() => null);
  if (!res.ok || !body) throw new Error(`Apps Script request failed (${res.status}).`);
  if (body.error) throw new Error(`Apps Script reported: ${body.error}`);
  return body.values || [];
}

module.exports = { readSheetValues };
