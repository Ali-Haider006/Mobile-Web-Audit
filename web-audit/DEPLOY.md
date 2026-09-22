# Putting this online

Written for the "I need a link to send my boss, I have no domain and no budget"
case. Three routes, with what each costs you in setup time and in limitations.

> Free hosting changes constantly, and provider names/limits here are from
> training data that has a cutoff — check current terms before committing. What
> does **not** change is the checklist below: use it to judge any host, and let
> the Setup screen give you the verdict rather than the marketing page.

## What this app needs from a host

| Requirement | Why | Deal-breaker? |
|---|---|---|
| PHP 8.1+ with `pdo_mysql`, `curl`, `simplexml`, `mbstring` | The app | **Yes** |
| MySQL 5.7+ / MariaDB 10.2+ | Storage | **Yes** |
| **Outbound HTTPS from PHP** to `googleapis.com` | Every audit is an API call | **Yes — and the one free hosts most often block** |
| `max_execution_time` ≥ 60s | One audit takes 10–40s | No — the queue retries timeouts, just noisily |
| Cron | Unattended scans | No — the browser drives the queue instead |
| SSH | Convenience | No — `setup.php` replaces it |

That third row is the one that quietly kills free PHP hosts. **Test it before you
invest any time**: deploy, open `setup.php`, press *Test the PageSpeed API*. If it
says outbound is blocked, that host can never run this tool — move on.

---

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
DB_HOST=sqlXXX.yourhost.com
DB_PORT=3306
DB_NAME=abc_web_audit
DB_USER=abc_web_audit
DB_PASS=...
PSI_API_KEY=AIza...
APP_PASSWORD=pick-something-long
SCORE_THRESHOLD=80
```

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
