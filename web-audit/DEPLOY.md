# Putting this online

The main route is **your own server over FTP**, which is what
`speed.pixelchefs.com` is. The free-hosting routes further down are kept for
reference; skip them unless you are back to having no server.

> Nothing in this file asks you to send a password to anyone. Every credential
> is typed once, on the server, into `config/local.php` — a PHP file, so even
> if the web server were misconfigured it returns a blank page rather than its
> contents. Do not paste credentials into a chat, a ticket or a commit.

## What this app needs from a host

| Requirement | Why | Deal-breaker? |
|---|---|---|
| PHP 8.0+ with `pdo_mysql`, `curl`, `simplexml`, `mbstring` | The app | **Yes** — set the version in the control panel; too old shows a clear message, not a 500 |
| MySQL 5.7+ / MariaDB 10.2+ | Storage | **Yes** |
| **Outbound HTTPS from PHP** to `googleapis.com` | Every audit is an API call | **Yes — and the one free hosts most often block** |
| `max_execution_time` ≥ 60s | One audit takes 10–40s | No — the queue retries timeouts, just noisily |
| Cron | Unattended scans | No — the browser drives the queue instead |
| SSH | Convenience | No — `setup.php` replaces it |

That third row is the one that quietly kills free PHP hosts. **Test it before you
invest any time**: deploy, open `setup.php`, press *Test the PageSpeed API*. If it
says outbound is blocked, that host can never run this tool — move on.

---

## Putting it on your own server (FTP + MySQL)

### 1. Build the upload

On your machine, in the `web-audit` folder:

```bash
php bin/package.php          # dist/mobile-web-audit.zip
```

Use `php bin/package.php --flat` instead if your host will not let you move the
document root — see step 3. Neither archive contains `config/local.php` or
`.env`, so it is safe to move around.

### 2. Sort out the database

The app needs **four** values. A host normally hands you three and leaves the
fourth unsaid, which is where this step usually stalls.

| Setting | What your host calls it | Notes |
|---|---|---|
| `db_host` | hostname, or the server IP | Use `localhost` when MySQL runs on the same machine as the site — it is faster and sidesteps the allow-list below. Use the hostname or IP only for a separate SQL server. |
| `db_user` | username | On cPanel-style panels it is prefixed with the account, e.g. `acct_appuser`. |
| `db_pass` | password | |
| `db_name` | **often not given at all** | The panel's "MySQL Databases" page lists it. It carries the same account prefix, and is frequently the same string as the username. |

If the panel shows no database, create one there, or:

```sql
CREATE DATABASE acct_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**Do not guess `db_name` more than once.** Fill in the other three, leave your
best guess in `db_name`, and open `setup.php`: when the name is wrong but the
credentials are right, it says so and **lists the databases that login can
actually see**. With shell access, `php bin/dbcheck.php` prints the same thing.

If the SQL server is a separate machine, its MySQL user usually has to be
allowed to connect from the web server's IP — a grant on the SQL side
(`'user'@'<web server ip>'`). Without it the connection is refused or simply
hangs; the app gives up after 10 seconds and says so rather than leaving you
with a blank page.

### 3. Upload

Point your FTP client at the host you were given and upload the zip, then unzip
it with the control panel's file manager. Unzipping on the server is worth the
extra step: a few dozen small files over FTP is slow, and a half-finished
upload is the usual cause of a mystery 500.

**Preferred layout** — document root points at `public/`:

```
/home/you/speed.pixelchefs.com/      <- unzip the normal package here
    public/                          <- set this as the document root
    src/  config/  bin/  db/         <- not reachable over HTTP at all
```

**Flat layout** — if the document root is fixed and you cannot move it, use
`--flat` and unzip straight into it. The app detects which layout it is in. The
folders that must never be served carry their own `.htaccess` denying
everything, which was verified against Apache 2.4: `src/`, `config/`, `db/`,
`bin/`, plus `.md` and `.sql` files, all answer 403. That guard needs
`AllowOverride All` (or at least `AllowOverride Limit`) on the directory —
standard on cPanel/Plesk, worth confirming on a hand-rolled vhost. On nginx
`.htaccess` does nothing, so use the preferred layout there, not the flat one.

### 4. Enter the credentials on the server

Create `config/local.php` with the file manager's editor — do not upload it, and
do not put real values in `.env` where a mis-served file leaks them as text:

```php
<?php
return [
    'db_host'      => 'your-sql-ip',
    'db_name'      => 'pixelchefs_audit',
    'db_user'      => 'your-sql-user',
    'db_pass'      => 'your-sql-password',
    'psi_api_key'  => 'your-pagespeed-key',
    'clickup_token'=> 'pk_...',
    'app_password' => 'a-long-random-passphrase',
    'app_timezone' => 'Asia/Karachi',
];
```

`app_password` is not optional here. This is a public URL: without it, anyone
who finds it can read client data, add sites and burn your API quota. The Setup
screen fails the deployment outright if it is missing.

### 5. Create the tables and check the install

Open `https://speed.pixelchefs.com/setup.php`. It creates the schema and then
checks PHP's version and extensions, the database, the schema, outbound HTTPS
to Google, and the login gate. Work top-down until everything is green; each
failure prints what to do about it. With shell access, `php bin/install.php`
and `php bin/doctor.php --api --public` do the same thing.

**Look at the page, not just the status code.** If you see raw `<?php` text,
the server is handing out source instead of running it — PHP is not wired up
for this vhost. Stop and fix that before going further.

### 6. Set the schedule

Twice a week, as your boss asked — Monday and Thursday at 6am:

```
0 6 * * 1,4 /usr/bin/php /home/you/speed.pixelchefs.com/bin/monitor.php --quiet
```

Check the PHP binary's path first (`which php`, or the control panel's cron
page usually shows it). Some panels have a separate, older CLI PHP than the web
one; if cron reports a version error, use the full path to the 8.x binary.

Run it once by hand first and watch the output without `--quiet`. `--dry-run`
audits nothing and creates nothing, which is the safe way to confirm the URL
list is what you expect.

### 7. Before you send the link round

- Load the site over **https**. If the panel offers a free Let's Encrypt
  certificate, turn it on; the login password crosses the network otherwise.
- Sign in and re-open `setup.php`. It names where each secret came from; both
  the PageSpeed key and the ClickUp token should read as coming from
  `config/local.php`. "From the database" means the file is missing that value
  and an old stored one is being used instead; "also in the database" means the
  file wins but a stale copy is still lying around. Both are worth clearing.
- Add one real URL, audit it, and confirm the ClickUp task lands in the right
  list with the right assignee. This is the first time the ClickUp API is
  genuinely exercised — everything up to here can be right while the token
  still lacks access to the list.

### Updating later

Rebuild the package, upload, unzip over the top, then run `bin/install.php` (or
open `setup.php`) so any new columns are added. `config/local.php` is never in
the archive, so it survives the overwrite untouched. Migrations only ever add
things, so the data is not at risk — but take the panel's database backup
before a big jump anyway.

---

## What free PHP hosting actually costs you

Tried and measured on InfinityFree, in the order the problems appeared. Every
one of these applies to shared hosting generally, so use it as a checklist:

| Symptom | Cause | Fix |
|---|---|---|
| HTTP 500 on every page | `Options -Indexes` in `.htaccess` — `Options` is not granted in `AllowOverride`, and a rejected directive 500s the whole directory | Ship only `IfModule`-guarded directives, or no `.htaccess` |
| HTTP 500 on every page | PHP version older than the code's syntax floor | Set the PHP version in the control panel |
| `open_basedir` warning on every request | Anything touching a path outside the document root — including a harmless `is_dir()` probe. The warning lands before `session_start()` and before redirect headers, breaking both | Never read outside the web root; keep the whole app inside it |
| Empty response / `ERR_EMPTY_RESPONSE` | The front end cuts the request while PHP waits on an external API | Keep outbound calls well inside the limit; never retry inside one request |
| `.env` may be downloadable | `.htaccess` protection is the only thing stopping it, and `.htaccess` is unreliable here | Put credentials in `config/local.php` — a PHP file is executed, never served |
| No SSH | `bin/*.php` unusable | Use `setup.php` in the browser |
| No cron | Scheduled scans unavailable | Drive the queue from the browser tab |

The pattern: on shared hosting you are debugging the host, not the app. If the
tool needs to work **today**, use Route B and keep the host for later.

## Route A — free PHP host with a free subdomain

Best when your boss needs a link that keeps working. No credit card.
Look for a host offering free PHP + MySQL + a subdomain (InfinityFree, AwardSpace
and similar have historically done this). You get something like
`your-name.rf.gd` — no domain purchase needed.

**1. Sign up and create the site.** Note the free subdomain it gives you.

**2. Create a MySQL database** in the control panel. Write down the four values —
they are *not* the ones in your local `.env`:

- host (a server name like `sqlXXX.yourhost.com`, **not** `127.0.0.1`)
- database name and username (usually both prefixed, e.g. `abc_web_audit`)
- password

**3. Upload the files, with everything except `public/` ABOVE the web root.**
Your FTP space has a web root inside it (`htdocs/`, `public_html/`, or similar).
Put the *contents* of `public/` in there, and the rest beside it:

```
/                      <- FTP root, NOT web-reachable
   bin/  config/  db/  src/  .env
   htdocs/             <- the web root
      index.php  setup.php  site.php  ...   (contents of public/)
      assets/  api/  .htaccess
```

The app works out its own root from where `_init.php` sits, so this layout needs
no code changes — and it keeps `.env`, with your database password and API key,
unreachable over the web. I tested this exact layout.

*(If your host gives you no folder above the web root, upload the whole project
and point the site at the `web-audit/public` subfolder instead. The shipped
`.htaccess` files deny `.env` and `.sql`, but above-the-web-root is safer.)*

**4. Create `.env`** in the FTP root (copy `.env.example`, edit it):

```ini
DB_HOST=<the MySQL hostname from your control panel>
DB_PORT=3306
DB_NAME=<your prefixed database name>
DB_USER=<your prefixed database user>
DB_PASS=<the database password>
PSI_API_KEY=<your PageSpeed Insights API key>
APP_PASSWORD=<a long random string of your choosing>
SCORE_THRESHOLD=80
```

Angle brackets are placeholders — replace them, brackets included. Keep real
values out of anything you commit: `.env` is gitignored, and secret scanners
flag files that merely *look* like they hold credentials.

`APP_PASSWORD` is not optional once this is public — see the checklist below.

**5. Open `https://your-subdomain/setup.php`.** It checks PHP, the extensions,
`.env`, the database connection and the schema, and tells you how to fix
whatever is wrong. Press **Install the schema** to create the tables (no SSH
needed), then **Test the PageSpeed API**.

**6. Send your boss the link** once Setup is all green.

---

## Route B — tunnel from your own machine (fastest)

Nothing to sign up for, and it works in about two minutes. Good for a demo
*today*; the link dies when you close the terminal or shut the machine down.

```powershell
# terminal 1 - the app, as you already run it
cd web-audit
php -S 127.0.0.1:8000 -t public

# terminal 2 - a public HTTPS URL pointing at it
cloudflared tunnel --url http://127.0.0.1:8000
```

`cloudflared` is a single downloadable binary (Cloudflare's free Quick Tunnel);
it prints a `https://something.trycloudflare.com` URL. `ngrok http 8000` does the
same with a free account.

Because it runs on your machine, outbound API calls, execution time and cron are
all unrestricted — none of the shared-hosting caveats apply. **Set `APP_PASSWORD`
first**: a tunnel URL is public the moment it exists.

---

## Route C — a free cloud VM

Best when this becomes something the team actually uses. Cloud providers offer
always-free small VMs (Oracle Cloud's has historically been the most generous);
a card is usually required for identity verification. You get root, a public IP —
so still no domain needed — real cron, and no execution-time limits.

Setup is the standard LAMP path: install `php-cli php-mysql php-curl php-xml`
and `mariadb-server`, clone the repo, point Apache/nginx at `web-audit/public`,
`php bin/install.php`, `php bin/doctor.php --api`. Budget 30–45 minutes, and add
HTTPS with Caddy or certbot if you later attach a domain.

---

## Before you share the link

- [ ] **`APP_PASSWORD` is set.** Without it, anyone with the URL can read your
      clients' data, add sites and burn your API quota. `setup.php` reports this
      as a hard failure when it detects it is running on a public hostname.
- [ ] **Restrict the API key.** In Google Cloud Console → Credentials, limit the
      key to the PageSpeed Insights API. A leaked unrestricted key is worse than
      a leaked restricted one.
- [ ] **`.env` is not web-reachable.** Visit `https://your-site/.env` — you want
      404 or 403, never a download. Above the web root, it cannot be served.
- [ ] **Setup is green**, with the API test passed on the host itself.
- [ ] **HTTPS.** Free hosts include it; use the `https://` link.

## Running scans without cron

Most free hosts have no cron. That is fine: open the site, press **Scan all
tracked pages**, and leave the tab open — the progress screen drives the queue
one URL at a time and survives being closed and reopened (the queue lives in the
database, and anything interrupted is retried).

If your host *does* offer cron, the commands are in the Settings screen.
