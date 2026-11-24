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

## Updating

Download the newer version, keep your `data` folder and `.env`, and run `docker compose up -d --build` again (or `npm install` then restart `npm start`).

## Settings (`.env`)

| Setting | What it does |
| --- | --- |
| `BASE_URL` | The address people use from outside. Used for links in emails. |
| `PORT` | Port the board listens on (default 8080). |
| `TRUST_PROXY`, `COOKIE_SECURE` | Set both to `true` behind Cloudflare Tunnel or another HTTPS proxy. |
| `MAX_UPLOAD_MB` | Largest upload allowed (default 25). |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` | Email alerts. Leave `SMTP_HOST` empty to turn them off. |
| `DATA_DIR` | Where the database and uploads are kept (default `./data`). |

## Files that can be uploaded

PDF, PNG, JPG, GIF, WebP, plain text, CSV, Markdown and MP4. Save Word documents as PDF first.

## How it's built

- `server.js`: a single Node.js server. It uses Node's built-in SQLite, so there's no database to install. It handles sign-in (passwords are hashed with scrypt; sessions are HttpOnly cookies), the board data, file uploads, live updates (server-sent events) and optional email through `nodemailer`.
- `public/index.html`: the whole board page, with no build step.
- `scripts/`: backup and password reset.
- `data/`: created on first run. Not tracked in git.
