<?php
/**
 * Event Board configuration template.
 *
 * Copy this file to config.php in this same directory and fill in real
 * values there. config.php is never committed (see .gitignore) — create it
 * directly on the server via cPanel's File Manager or FTP, not through
 * chat or a commit. See the README's "Deploying to cPanel" section for the
 * full walkthrough.
 *
 * Required: DB_HOST, DB_NAME, DB_USER, DB_PASS (lib/db.php). Every other
 * constant below is optional — each one already has a safe default
 * (usually "this feature is off") if config.php never defines it at all;
 * the defined('X') ? X : <default> pattern throughout lib/ and
 * public_html/*.php exists for exactly that reason. Uncomment only the
 * ones you actually need.
 */
declare(strict_types=1);

/* ---------- database (required) ----------
 * Create the database and database user through cPanel's "MySQL®
 * Databases" tool first — it generates the real values below (cPanel
 * accounts typically prefix both with your cPanel username, e.g.
 * "myuser_eventboard" / "myuser_ebuser"). Paste what it gives you; don't
 * invent values here.
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'changeme_eventboard');
define('DB_USER', 'changeme_ebuser');
define('DB_PASS', 'changeme');

/* ---------- site address ----------
 * Only used to build the "Open the card" link in email alerts
 * (lib/mailer.php). Leave commented out to omit that link. No trailing
 * slash needed — it's stripped if present.
 */
// define('BASE_URL', 'https://exdocreative.com');

/* ---------- proxy / cookie security ----------
 * Leave both commented out on plain shared cPanel hosting, where Apache
 * itself terminates TLS — this is the expected setup for this app, and
 * lib/auth.php's is_https_request() already detects $_SERVER['HTTPS']
 * directly in that case, so the session cookie gets its Secure flag
 * automatically with no config needed.
 * Only define TRUST_PROXY as true if this site sits behind something that
 * terminates TLS itself and forwards CF-Connecting-IP / X-Forwarded-For /
 * X-Forwarded-Proto (e.g. a Cloudflare proxy in front of cPanel). Trusting
 * those headers on a host that ISN'T actually behind such a proxy would
 * let any visitor spoof their own IP address (defeating login rate
 * limiting) just by sending a fake header.
 */
// define('TRUST_PROXY', true);
// define('COOKIE_SECURE', true);

/* ---------- uploads ----------
 * UPLOAD_DIR defaults to public_html/private/uploads if undefined — only
 * set it if uploads specifically need to live somewhere else. Raising
 * MAX_UPLOAD_MB here alone is not enough to accept bigger files: PHP's own
 * post_max_size limit must also be raised, since the browser sends the
 * uploaded bytes as the raw POST body, not a multipart form file — see
 * .user.ini in this same directory and the README's "Deploying to cPanel"
 * section.
 */
// define('UPLOAD_DIR', __DIR__ . '/private/uploads');
define('MAX_UPLOAD_MB', 25);

/* ---------- email alerts ----------
 * Off by default — every mail-related code path is a no-op until
 * MAIL_ENABLED is defined true. Uses PHP's built-in mail(), which cPanel
 * wires up out of the box, so no SMTP settings are needed here. After
 * turning this on, use Team → Logins → Send me a test email to check it,
 * and see the README's note on SPF/DKIM for deliverability.
 */
// define('MAIL_ENABLED', true);
// define('MAIL_FROM', 'Event Board <no-reply@exdocreative.com>');

/* ---------- backups (cron/backup.php) ----------
 * BACKUP_DIR defaults to public_html/private/backups if undefined.
 * BACKUP_KEEP is how many dated backups to keep before the oldest are
 * deleted after each run; defaults to 14.
 */
// define('BACKUP_DIR', __DIR__ . '/private/backups');
// define('BACKUP_KEEP', 14);

/* ---------- work order sheet sync (cron/workorder_sync.php) ----------
 * Entirely off unless WORKORDER_APPS_SCRIPT_URL is defined. See the
 * README's "Work order sheet sync" section for how to deploy the Apps
 * Script Web App and get these two values from it. WORKORDER_SHEET_NAME /
 * WORKORDER_SHEET_RANGE only need to be set if your sheet's tab name or
 * range differs from the Apps Script's own defaults ('Sheet2' /
 * 'A1:K5000').
 */
// define('WORKORDER_APPS_SCRIPT_URL', 'https://script.google.com/macros/s/XXXXXXXX/exec');
// define('WORKORDER_APPS_SCRIPT_TOKEN', 'paste-the-long-random-secret-here');
// define('WORKORDER_SHEET_NAME', 'Sheet2');
// define('WORKORDER_SHEET_RANGE', 'A1:K5000');
