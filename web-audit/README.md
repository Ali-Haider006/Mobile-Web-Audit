# Mobile Web Audit

A small PHP + MySQL tool for keeping client sites above the Core Web Vitals bar
we hold them to. Point it at a website, it reads the sitemap, you choose which
pages to track, and it scores them through Google's PageSpeed Insights API on
the **mobile** strategy. Every score is kept, so each page has a history you can
graph, and any page that lands below the target opens a task automatically.

- **Sitemap import with exclusions** — robots.txt discovery, sitemap indexes,
  `.gz` and plain-text sitemaps. Tick the pages worth tracking; untick the rest.
  Wildcard rules (`*/tag/*`) keep the junk out on every future import.
- **Mobile only.** Every audit runs `strategy=mobile` — that is what Google
  ranks on and what the client sees.
- **History and graphs.** Performance score, LCP, CLS and TBT over time, per
  page, plus the site's average score trend.
- **Tasks below the target.** Score under 80 (configurable, per site) opens a
  task with the failing metrics and Lighthouse's biggest wins attached. A later
  audit at or above the target resolves it.
- **Quick check.** Paste 3–4 URLs, get their mobile scores now. Results still
  land in history and still open tasks.

No framework, no Composer, no CDN — plain PHP, a MySQL database, and charts
drawn as inline SVG so it works behind a firewall.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `simplexml`, `mbstring`
- MySQL 5.7+ or MariaDB 10.2+
- A [PageSpeed Insights API key](https://developers.google.com/speed/docs/insights/v5/get-started)
  — optional but strongly recommended; without one Google rate-limits you to a
  trickle. The free quota is 25,000 requests/day.

## Install

```bash
# 1. Database
mysql -u root -p -e "CREATE DATABASE web_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p web_audit < db/schema.sql      # or: php bin/install.php

# 2. Config
cp .env.example .env         # set DB_*, PSI_API_KEY, optionally APP_PASSWORD

# 3. Point the web server's document root at web-audit/public
```

Anything outside `public/` must not be web-reachable. Set `APP_PASSWORD` in
`.env` to put a shared-password login in front of the UI (leave it empty if the
tool already sits behind a VPN or basic auth).

### Run it locally

PHP's built-in server is enough for local use - no Apache or nginx needed:

```bash
cd web-audit
php bin/doctor.php          # checks PHP, .env, MySQL and the schema
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>. Leave that terminal running; `Ctrl+C` stops it.

`bin/doctor.php` prints a fix for anything it finds wrong. Add `--api` to spend
one API request proving the PageSpeed key works end to end.

On XAMPP/MAMP/Laragon you can instead put the project in `htdocs`/`www` and
point a vhost at `web-audit/public`, but the built-in server is simpler and the
`php` binary is already installed (`C:\xampp\php\php.exe`, or
`/Applications/MAMP/bin/php/php8.x/bin/php`).

## Using it

1. **Sites → add a website.** Paste the URL, give it a client name.
2. **Import sitemap.** It reports what it found. Either keep "everything except
   the exclusion rules", or tick URLs individually. Unticked URLs are still
   stored — they are remembered as excluded and never audited, and a later
   re-import will not quietly start tracking them again.
3. **Scan all tracked pages.** The progress screen drives the queue from the
   browser, one URL at a time (PageSpeed takes 10–40 s per URL). You can pause,
   close the tab and let cron finish it, or cancel the rest.
4. **Read the results.** A page's own screen graphs its score and Core Web
   Vitals over time and lists Lighthouse's biggest wins from the last audit.
5. **Work the tasks.** Anything below target is already waiting under Tasks with
   an assignee field and a status.

### Scheduling

The browser is only one way to drain the queue. For unattended runs:

```cron
# Monday 03:00 - refresh sitemaps and queue every active site
0 3 * * 1 php /path/to/web-audit/bin/scan.php --all --import

# every 5 minutes - audit up to 20 queued URLs
*/5 * * * * php /path/to/web-audit/bin/worker.php --max=20 --quiet
```

| Command | What it does |
|---|---|
| `bin/scan.php --all [--import] [--run]` | Queue every active site; `--import` re-reads sitemaps first, `--run` also drains the queue |
| `bin/scan.php --site=3` | Queue one site |
| `bin/worker.php --max=20` | Audit up to 20 queued URLs (`--run=ID` for one scan, `--sleep=S` between calls) |
| `bin/doctor.php [--api]` | Pre-flight check: PHP, extensions, `.env`, MySQL, schema, API key |
| `bin/install.php` | Load `db/schema.sql` |
| `bin/selftest.php` | Offline checks of the URL, sitemap and PageSpeed parsing — no DB, no network |

## How scoring and tasks work

The stored score is Lighthouse's **mobile performance score** (0–100) from the
PageSpeed Insights API, alongside the lab metrics (LCP, FCP, CLS, TBT, Speed
Index, TTFB) and, when Google has enough real-user data, the CrUX field numbers
(LCP, CLS, INP and the overall verdict).

After each audit:

- score **below** the target → open a task, or refresh the one already open with
  the latest score and metrics. Under 50 it is filed as critical, otherwise high.
- score **at or above** the target → any open task for that page is resolved
  with a note saying what it re-scored.

The target is 80 by default (Settings), and a site can override it.

## Layout

```
bin/        CLI: install, scan, worker, selftest
config/     defaults, overridden by .env
db/         schema.sql
public/     document root - one file per screen, plus assets/ and api/queue.php
src/        Config, Database, Settings, Auth, Http, Sitemap, PageSpeed,
            Importer, AuditRunner, Helpers, Repo/*, views/*
```

`src/PageSpeed.php` and `src/Sitemap.php` keep their parsing in pure static
methods, which is what `bin/selftest.php` exercises.

## Notes

- Audits are stored per page forever; nothing prunes them. A site with 200
  tracked pages scanned weekly adds ~10k rows a year — small, but worth knowing.
- Excluding a page keeps its history. Deleting a page removes it.
- The queue is safe to run from cron and a browser tab at once: an item is
  claimed with a conditional update, and anything stuck in `running` for 15
  minutes is retried (up to 3 attempts).
