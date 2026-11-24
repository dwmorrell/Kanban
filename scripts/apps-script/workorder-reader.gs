// Google Apps Script - read-only Work Order sheet reader.
//
// This is NOT run by Node; it is not part of the npm project at all. It's a
// reference copy of a script you paste into a *standalone* Apps Script
// project (script.google.com -> New project), which you then deploy as a
// Web App. The server polls that Web App's URL instead of calling Google's
// Sheets API directly, so there is no Google Cloud project, no service
// account, and no billing involved anywhere in this path.
//
// This script only ever calls read methods (getRange/getDisplayValues). It
// has no call to setValue, appendRow, setValues, deleteRow, or anything else
// that could change the sheet - keep it that way.
//
// Setup (see README.md "Work order sheet sync" for the full walkthrough):
//   1. Fill in SHEET_ID and SECRET below.
//   2. script.google.com -> New project -> paste this file's contents.
//   3. Deploy -> New deployment -> Web app -> Execute as: Me,
//      Who has access: Anyone -> Deploy. Copy the Web app URL.
//   4. Put that URL and SECRET into .env as WORKORDER_APPS_SCRIPT_URL and
//      WORKORDER_APPS_SCRIPT_TOKEN.

const SHEET_ID = 'PASTE_THE_SPREADSHEET_ID_HERE';
const SECRET = 'PASTE_A_LONG_RANDOM_SECRET_HERE'; // must match WORKORDER_APPS_SCRIPT_TOKEN in .env
const DEFAULT_SHEET_NAME = 'Sheet2';
const DEFAULT_RANGE = 'A1:K5000';

function doGet(e) {
  const params = (e && e.parameter) || {};
  if (params.token !== SECRET) {
    return jsonOutput({ error: 'unauthorized' });
  }
  try {
    const ss = SpreadsheetApp.openById(SHEET_ID);
    const sheet = ss.getSheetByName(params.sheet || DEFAULT_SHEET_NAME);
    if (!sheet) return jsonOutput({ error: 'sheet not found: ' + (params.sheet || DEFAULT_SHEET_NAME) });
    const values = sheet.getRange(params.range || DEFAULT_RANGE).getDisplayValues();
    return jsonOutput({ values: values });
  } catch (err) {
    return jsonOutput({ error: String(err) });
  }
}

function jsonOutput(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
