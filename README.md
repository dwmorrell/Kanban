# Event Board

A shared board for the venue. It tracks every event across **Reelworks, Tracks, Lounge and Basement** from first inquiry to settlement, alongside the crew's fabrication and maintenance tasks.

- **Events board.** Columns for each booking stage (Inquiry, Hold, Offer out, Confirmed, Advancing, Show ready, Settled). Drag cards between them. Rename, reorder, add or remove columns from the **⋯** menu on each column.
- **One card per event.** Each card has a room, date, doors time, contact, booking notes and checklists for lighting, audio, staging, deco and rider verification. You can also attach riders, stage plots and screenshots.
- **Holds and clashes.** Two events in the same room on the same date are flagged on each other's cards. If both are confirmed, the cards say **Double-booked**.
- **Tasks board.** Backlog, Up next, In progress and Done, for work that isn't tied to one event. Tasks in Up next and In progress are numbered in priority order, and you drag them to reprioritise. Assign tasks to fabricators and other crew.
- **@ mentions.** In the "Waiting on" box on any card, type `@` and pick someone, then what you need from them. They see it under **Waiting on me** and, if email is set up, get an email.
- **Linked cards.** Link an event to last year's version of it, or to the build task behind it, so its notes and files are one click away.
- **Calendar.** A month view of all events, plus task due dates.

Everyone signs in with an email and password. The board updates live for everyone who has it open.

---

## What you need

- The on-site computer that will run the board. Any always-on Windows, Mac or Linux machine works, including a mini PC or a Raspberry Pi 4/5.
- **Either** Docker (easiest to keep running) **or** Node.js version 22.13 or newer.

Everything the board stores lives in one folder called `data`: the database file and uploaded files. Back that folder up (see below).

## Install with Docker (recommended)

1. Install Docker Desktop (Windows/Mac) or Docker Engine (Linux).
2. Download this repository: on GitHub, click **Code → Download ZIP**, then unzip it. Or run `git clone https://github.com/dwmorrell/Kanban.git`.
3. In the project folder, copy `.env.example` to a new file named `.env`. You can leave it as it is for now.
4. Open a terminal in the project folder and run:
   ```
   docker compose up -d --build
   ```
5. On the same computer, open **http://localhost:8080**. The first visit asks you to create the admin account.

Docker restarts the board automatically after a reboot.

## Install without Docker

1. Install Node.js 22.13 or newer from nodejs.org (the LTS version is fine).
2. In the project folder, run `npm install` once, then `npm start`.
3. Open **http://localhost:8080** and create the admin account.

To keep it running after reboots without Docker, use a process manager such as `pm2` (`npm install -g pm2`, then `pm2 start npm --name eventboard -- start` and `pm2 save`).

## Adding staff

Sign in as admin and open **Team**.

- **Logins** (admins only): add each person's name, email and a temporary password. Send them the board link, their email and the password. They can change it under **Team → Your account**. Make another person an admin from the same list.
- **Crew**: add people who need tasks or @ mentions but won't sign in, such as a freelance fabricator. Everyone with a login is already included.

If someone forgets their password, an admin uses **Reset password** next to their login. If every admin is locked out, run this on the server:
```
npm run reset-password -- someone@yourvenue.com NewPassword123
```
With Docker: `docker compose exec eventboard node scripts/reset-password.js someone@yourvenue.com NewPassword123`

## Reaching it from outside the building

The board listens on port 8080 on the on-site computer. To reach it from anywhere without opening ports on the venue router, use **Cloudflare Tunnel**. It's free and gives you a normal `https://` address.

1. You need a domain name managed by Cloudflare. A cheap domain moved to Cloudflare's free plan works.
2. In the Cloudflare dashboard, go to **Zero Trust → Networks → Tunnels**, create a tunnel, and follow the steps to install `cloudflared` on the on-site computer.
3. Add a public hostname such as `board.yourvenue.com` that points to `http://localhost:8080`.
4. In `.env`, set:
   ```
   BASE_URL=https://board.yourvenue.com
   TRUST_PROXY=true
   COOKIE_SECURE=true
   ```
   Then restart the board (`docker compose up -d`, or restart `npm start`).

For an extra lock, Cloudflare Access can require a one-time email code before anyone even reaches the sign-in page.

If you'd rather not put the board on the public internet at all, **Tailscale** is an alternative. Staff install the Tailscale app on their phone or laptop and reach the board privately.

## Email alerts

When someone is @ mentioned in "Waiting on", or a task is assigned to them, the board can email them a link to the card. Fill in the SMTP lines in `.env` with any mail service (Google Workspace, Microsoft 365, your web host, Postmark, Mailgun) and restart. Then use **Team → Logins → Send me a test email** to check it.

Without email, people still see their requests under **Waiting on me** when they open the board.

## Backups

The board backs itself up automatically every night (3am server time by default) with no cron job or Task Scheduler entry to set up — it writes a dated copy of the database and all uploaded files to `data/backups/`, and keeps the most recent 14 by default, deleting older ones. Change the time or how many are kept with `BACKUP_HOUR` and `BACKUP_KEEP` in `.env` (see `.env.example`).

You can still run a backup on demand with `npm run backup` (with Docker: `docker compose exec eventboard node scripts/backup.js`). It's safe to run while the board is in use.

Nightly backups only protect against mistakes on this machine. Periodically copy `data/backups/` somewhere off the machine too, such as a USB drive or cloud storage, in case the machine itself is lost or damaged.

To restore, stop the board and replace `data/eventboard.db` and `data/uploads/` with the copies from a backup.

## Work order sheet sync (optional)

If the crew already logs work orders in a Google Sheet, the board can turn each row into a card on the Tasks board automatically, and keep updating that card whenever the row changes (for example, marking a row Completed in the sheet moves its card to Done). This only ever **reads** the sheet — it is given no permission to change it.

This works through a small Google Apps Script you deploy yourself from your own Google account. It's free, needs no Google Cloud project, no billing, and no API key — just your existing access to the sheet.

1. Open the sheet's URL and copy the long ID out of it: `https://docs.google.com/spreadsheets/d/`**`THIS_PART`**`/edit`.
2. Make up a long random secret (for example, run `node -e "console.log(require('crypto').randomBytes(24).toString('hex'))"` anywhere you have Node installed). This stops anyone else who finds the script's URL from reading the sheet through it.
3. Go to [script.google.com](https://script.google.com/home) and click **New project** (not "Extensions → Apps Script" from inside the sheet itself — a separate, standalone script never gets attached to the spreadsheet file at all).
4. Delete the placeholder code and paste in the contents of this repo's `scripts/apps-script/workorder-reader.gs`.
5. Fill in `SHEET_ID` and `SECRET` near the top with the values from steps 1 and 2.
6. **Deploy → New deployment → type: Web app.** Set **Execute as: Me** and **Who has access: Anyone**, then Deploy. Copy the Web app URL it gives you (it authorizes using your own Google account, with whatever access to the sheet you already have — Viewer is enough).
7. In `.env`, set `WORKORDER_APPS_SCRIPT_URL` to that URL and `WORKORDER_APPS_SCRIPT_TOKEN` to the secret from step 2. Restart the board.

"Who has access: Anyone" makes the URL itself reachable by anyone who has it, the same way a webhook is — the secret token is what actually protects it, so keep it as private as a password. If you ever edit the script, use **Deploy → Manage deployments → Edit → New version** to publish the change; saving alone doesn't update the live URL.

By default it checks the sheet every 15 minutes (`WORKORDER_SYNC_MINUTES`) and reads the tab/range in `WORKORDER_SHEET_NAME` / `WORKORDER_SHEET_RANGE` (default `Sheet2` / `A1:K5000` — change these if the work order tab has a different name). It expects the same eleven columns as the source sheet (Status, Item needing repair, report date, Where, Priority, Created by, Assigned to, End date, Notes, Deliverable, Closing Notes) in that order; if the header row doesn't match, it skips that sync cycle and logs a warning rather than guessing.

Checklists, file uploads, links and @ mentions added on the board to a synced card are never touched by a resync — only the fields that come from the sheet (title, column, assignee, due date, room, notes) are kept in sync. If a row is deleted from the sheet, its card is removed from the board.

## Deploying the PHP version to shared cPanel hosting

Everything above describes the Node.js version, for an always-on machine you control. This repo also includes a separate PHP+MySQL port of the board, under `public_html/`, built for plain shared cPanel hosting where you only have FTP and a MySQL database — no SSH guaranteed, no persistent process, no Node.js. It's the same board; which version you deploy depends on what hosting you have.

The PHP version replaces the Node version's two background timers with cPanel Cron Jobs (step 6 below) and its SQLite database with MySQL, but everything else — the board itself, sign-in, file uploads, email alerts, work order sheet sync — works the same way.

### 1. Create the database

cPanel → **MySQL® Databases** → create a database and a database user, then add that user to the database with **All Privileges**. Note the three names it generates (cPanel usually prefixes the database and user name with your cPanel username, e.g. `myuser_eventboard` / `myuser_ebuser`) — you'll need them in step 3.

No separate schema-import step is needed for a brand new board: the first request (or the first cron script run) automatically creates every table it needs. If you're restoring from an existing backup instead, see "Backups" below.

### 2. Upload the files

Upload everything **inside** this repo's `public_html/` folder to your hosting account's web root via FTP — not the `public_html/` folder itself, its contents. Make sure your FTP client is set to show hidden files, so `.htaccess` and `.user.ini` actually get uploaded; both are easy to upload and both matter (routing and upload size limits respectively).

### 3. Configure `config.php`

Copy `config.sample.php` to `config.php` in the same folder, directly on the server (cPanel's File Manager, or rename after FTP upload) — never by committing it or pasting real credentials into chat. Fill in the real `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` from step 1. `config.sample.php` documents every other optional setting (email alerts, backups, work order sync, etc.) inline.

### 4. Set folder permissions

FTP-created folders often land with permissions too restrictive for PHP to write to. Via cPanel's File Manager, set `private/`, `private/uploads/` and `private/backups/` to `755` (right-click → Permissions).

### 5. Create the admin account

Visit the site. The first visit prompts you to create the admin account, same as the Node version.

### 6. Set up cPanel Cron Jobs

cPanel → **Cron Jobs** → **Add New Cron Job**, once for each job below. cPanel's cron environment may run a different PHP version than your site's vhost — cPanel's Cron Jobs page usually tells you the exact versioned binary path to use (e.g. `/usr/local/bin/php81`) instead of a bare `php`; use whatever it suggests for your account in place of the path shown here.

Nightly backup (replaces the Node version's 3am timer):
```
0 3 * * * /usr/local/bin/php81 /home/yourcpaneluser/public_html/cron/backup.php >/dev/null 2>&1
```

Work order sheet sync (optional — only does anything once `WORKORDER_APPS_SCRIPT_URL` is set in `config.php`; replaces the Node version's 15-minute timer):
```
*/15 * * * * /usr/local/bin/php81 /home/yourcpaneluser/public_html/cron/workorder_sync.php >/dev/null 2>&1
```

cPanel Cron Jobs run in the server's own system timezone, not something this app controls — set the times above with that in mind. After adding each job, run it once manually (or just wait for its first scheduled run) to confirm it works before relying on the schedule.

### Resetting a password with no admin signed in

`cron/reset_password.php` is the PHP equivalent of `npm run reset-password`. With SSH or cPanel's Terminal app:
```
php public_html/cron/reset_password.php someone@yourvenue.com NewPassword123
```
Without either, run it once via a temporary Cron Job instead: add a one-off job with that exact command (full PHP path, as in step 6), wait for it to fire, confirm the new password works, then **delete that cron entry** — it's a one-time reset, not something that should keep running on a schedule.

### Upload size

`POST /api/files` sends the uploaded file as the raw request body, so PHP's `post_max_size` — not `upload_max_filesize` — is what actually limits it here. The `.user.ini` you uploaded in step 2 already raises both of these, plus `memory_limit` and `max_execution_time`, with headroom over `config.php`'s default `MAX_UPLOAD_MB` of 25. If you raise `MAX_UPLOAD_MB` in `config.php`, raise `.user.ini`'s `upload_max_filesize`/`post_max_size` to match. `.user.ini` changes can take up to 5 minutes to take effect — don't panic-debug an upload failure immediately after editing it.

### Backups

Same idea as the Node version's nightly backup, just driven by the cPanel Cron Job from step 6 instead of a built-in timer. Each run writes a dated folder under `private/backups/` containing `dump.sql` (a plain data-only SQL dump, not a `mysqldump` file — this works even on hosts that disable `exec()`/`shell_exec()`) and a copy of `private/uploads/`, then deletes any backups beyond the most recent `BACKUP_KEEP` (default 14) and purges deleted-card records older than 30 days.

To restore: get a fresh copy of the schema in place (visiting the site once against an empty, newly-created database is enough), import that backup's `dump.sql` via phpMyAdmin or cPanel's "MySQL® Databases" import tool, then copy that backup's `uploads/` folder back over `private/uploads/`.

As with the Node version, periodically copy `private/backups/` somewhere off this hosting account too — it only protects against mistakes on this account, not against losing the account itself.

### Email deliverability

PHP's `mail()` needs no setup to start working on cPanel once `MAIL_ENABLED` is turned on in `config.php`, but shared-IP cPanel hosts are more likely to land in spam without SPF/DKIM configured for the sending domain — check cPanel's **Email → Authentication** page after turning email alerts on.

## Updating

Download the newer version, keep your `data` folder and `.env`, and run `docker compose up -d --build` again (or `npm install` then restart `npm start`). For the PHP version, keep `config.php` and the `private/` folder, and re-upload the rest of `public_html/` via FTP.

## Settings (`.env`)

| Setting | What it does |
| --- | --- |
| `BASE_URL` | The address people use from outside. Used for links in emails. |
| `PORT` | Port the board listens on (default 8080). |
| `TRUST_PROXY`, `COOKIE_SECURE` | Set both to `true` behind Cloudflare Tunnel or another HTTPS proxy. |
| `MAX_UPLOAD_MB` | Largest upload allowed (default 25). |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` | Email alerts. Leave `SMTP_HOST` empty to turn them off. |
| `DATA_DIR` | Where the database and uploads are kept (default `./data`). |
| `BACKUP_HOUR`, `BACKUP_KEEP` | When the nightly backup runs and how many to keep. See Backups above. |
| `WORKORDER_APPS_SCRIPT_URL`, `WORKORDER_APPS_SCRIPT_TOKEN`, `WORKORDER_SHEET_NAME`, `WORKORDER_SHEET_RANGE`, `WORKORDER_SYNC_MINUTES` | Optional work order sheet sync. See Work order sheet sync above. |

## Files that can be uploaded

PDF, PNG, JPG, GIF, WebP, plain text, CSV, Markdown and MP4. Save Word documents as PDF first.

## How it's built

- `server.js`: a single Node.js server. It uses Node's built-in SQLite, so there's no database to install. It handles sign-in (passwords are hashed with scrypt; sessions are HttpOnly cookies), the board data, file uploads, live updates (server-sent events) and optional email through `nodemailer`.
- `public/index.html`: the whole board page, with no build step.
- `scripts/`: backup, password reset, and the optional read-only work order sheet sync.
- `data/`: created on first run. Not tracked in git.
