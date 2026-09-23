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

- PHP 8.0+ with `pdo_mysql`, `curl`, `simplexml`, `mbstring` (8.1+ recommended)
- MySQL 5.7+ or MariaDB 10.2+
- A [PageSpeed Insights API key](https://developers.google.com/speed/docs/insights/v5/get-started)
  — optional but strongly recommended; without one Google rate-limits you to a
  trickle. The free quota is 25,000 requests/day. It goes in `.env` as
  `PSI_API_KEY`; like the ClickUp token it is never stored in the database or
  editable through the UI.

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

Putting it online for someone else to look at? See **[DEPLOY.md](DEPLOY.md)** —
free hosting options, the above-the-web-root layout for hosts with no SSH, and
the checklist to run through before sharing the link.

Local check without a web server:

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
| `bin/doctor.php [--api] [--public]` | Pre-flight check: PHP, extensions, `.env`, MySQL, schema, API key. The same checks are on `setup.php` in the browser, for hosting without SSH |
| `bin/install.php` | Load `db/schema.sql`, then add any columns an older install is missing |
| `bin/selftest.php` | Offline checks of the URL, sitemap and PageSpeed parsing — no DB, no network |
| `bin/integration-test.php` | End-to-end check against your database — imports, tracking, queue, tasks, every UI query. Safe on a live database: it only touches sites on reserved `.invalid` domains it creates and removes, and spends no API calls |

## The Monitor screen

The front door. One table of the URLs being watched:

| URL | Score | Change | ClickUp list | Assignee | Task | Last audit | On |

Paste three or four URLs, pick the list and the person their tasks should go to,
and leave it. The schedule does the rest — nobody has to be watching when a
scan finishes.

Set the schedule in cron (the lines are on the Settings screen):

```cron
0 6 * * 1 php /path/to/web-audit/bin/monitor.php --quiet
0 6 * * 4 php /path/to/web-audit/bin/monitor.php --quiet
```

`bin/monitor.php` audits every active URL and acts on each result. Add
`--dry-run` to see what it would audit without spending API calls.

### What happens to a score

| Result | What the tool does |
|---|---|
| Below target | Opens a task and creates the ClickUp task in that URL's list, assigned to that URL's person |
| Below target, task already open | Comments the new score on the existing ClickUp task — never a duplicate |
| Recovered | Comments that it recovered and resolves the task here. **A person closes it in ClickUp** (there is a setting to auto-close, off by default) |
| Fails again after recovering | A **new** task, linked back to the previous ones, with their ClickUp links listed in the description |
| Hovering on the target | Nothing. A task opens below target but only resolves once the score is clearly above it, so 79/81/79 does not churn |
| The audit itself failed | No task — a timeout is not a performance problem. After three in a row it says so |

The two thresholds and the recovery behaviour are on the Settings screen.

Where a task goes is resolved per URL first, then the site, then the global
default — same for the assignee.

### When a client goes away

Losing a client is not the same as making a typo, so there are two actions on
the **Sites** screen and they are deliberately different.

| | Stop auditing | Delete |
|---|---|---|
| Scheduled audits | Stop | Stop |
| Score history | Kept | **Erased** |
| Open tasks here | Closed out as *ignored*, with the reason | Erased |
| Tasks in ClickUp | A comment saying the page is no longer audited | The same comment, then the local record goes |
| Reversible | Yes — **Resume** | No |

**Stop auditing** is the one you want when you have been fired, when a client is
paused, or when a site is mid-rebuild. Everything stays: resume it months later
and the graphs still show what the scores were. **Delete** is for a URL added by
mistake, or for a client who has asked you to hold nothing.

Both leave the ClickUp tasks alone beyond a comment. Whoever owns that list
decides what happens to work already assigned to a person — the same rule the
tool follows when a page recovers.

Switching a single URL off with its **On** tick is the same idea at URL scale.
It drops out of the schedule but stays on the Monitor screen under *Not being
audited*, with a Resume button, so it is never a one-way door.

## ClickUp

Tasks this tool opens can be created in ClickUp, after you have reviewed them.

**Set up once**

1. In ClickUp: **Settings → Apps → Generate** a personal API token (`pk_...`).
   It carries your own permissions, so it can only reach lists you can.
2. Put it in `.env` as `CLICKUP_TOKEN` (or `clickup_token` in
   `config/local.php`). Tokens are never stored in the database and cannot be
   entered through the UI — Settings only shows a masked value and where it was
   read from.
3. In **Settings → ClickUp**, press **Load lists from ClickUp**. That walks
   Workspace → Space → Folder → List and caches the result, so lists are picked
   by name rather than by an ID you had to look up. Press it again after adding
   lists in ClickUp.
4. Choose a **default list**, and optionally a different list per site under
   **site settings** — that is how one workspace holds a list per client.

**Assigning**

Loading lists also loads the people in your workspaces, so the review screen has
an **Assign to** dropdown per task. The first load also works out who the token
in `.env` belongs to, marks them `(you)` in every dropdown, and makes them the
**default assignee** - the person who set the tool up is usually the person who
wants the tasks. Change it in Settings whenever you like; once you have chosen,
later refreshes leave your choice alone. Whoever is assigned is set as the
ClickUp assignee, and their name is copied onto the task here too, so both lists
agree on who owns it.

**The flow after a scan**

When a scan finishes, the progress screen offers *Create ClickUp tasks (N)* for
the pages that came back below target. That opens a review screen where you:

- pick the **list** (pre-selected from the site's setting, or the default)
- **untick** anything you do not want
- **edit** each task's name, markdown description and priority

Nothing reaches ClickUp until you press **Create**. Each result is reported
separately, so a partial failure is visible and the rest still go through.
Edits apply to the ClickUp task only — this tool keeps its own audit detail,
which it rewrites on the next scan.

A single task can be sent the same way from **Tasks → Review & send to
ClickUp**. A task already in ClickUp shows a link instead and is never sent
twice, so re-running the review after a failure is safe.

The generated description carries the score against target, the page URL, the
failing metrics and Lighthouse's biggest wins, with priority mapped from ours
(critical → Urgent, high → High). Set **This tool's URL** in Settings and it
also links back to the page's history here.

A ClickUp failure never breaks an audit: the error is recorded against the task
and shown on the review screen, and the scan finishes regardless.

## Public report links

The person fixing a page often has no login here. Any page's report — or a whole
scan's results — can be shared as a **read-only public link**:

- **Page → Create a public link**, or **Scan → Create a public link for this scan**
- The link opens a standalone report: scores, Core Web Vitals, the history
  graphs and Lighthouse's biggest wins, with no navigation into the rest of the
  tool and no login
- **Revoke** at any time; the link then reads "Link not available"
- Views are counted, so you can see whether it was opened

ClickUp tasks include one automatically (turn it off in Settings), which is what
makes an assignee without an account able to act on the task.

What to know before sending one:

- The token is 16 random bytes as 32 hex characters, and **that is the whole
  security model** — anyone with the link can read that report, so treat it as
  public. Malformed tokens are rejected before they reach the database.
- Pages are served `noindex, nofollow, noarchive`, as a header and a meta tag,
  so they should stay out of search results — but that is a request to crawlers,
  not a guarantee.
- A link exposes **only its own target**, never the site list or any other page.
- Set **This tool's URL** in Settings so links generated by a background job
  have the right hostname.

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

## Look and feel

The UI uses the **wpx-marine-feature-catalog** design system — marine teals with
a magenta accent, Georgia for display type, mono for labels, square corners and
hairline rules. The CSS is in two layers, so the design is swappable in one file:

| File | Holds | Touch it when |
|---|---|---|
| `public/assets/theme.css` | **Only** design tokens — every colour, font stack and measure, in a light block and two dark blocks | The design system changes |
| `public/assets/app.css` | **Only** layout and components, all reading `var(--token)` | The UI changes |

`bin/selftest.php` keeps that seam honest and fails if it erodes: `app.css` may
contain no raw colour, every `var()` it uses and every token `charts.js` reads
must be defined in `theme.css`, and the two dark blocks (one for the OS setting,
one for the toggle) must stay identical.

Token values are verbatim from the catalog, with two deliberate changes, both
commented in `theme.css`:

- `--series-*` / `--chart-*` are **added** — the roles `charts.js` reads for chart
  ink. The data line is `--teal`, which clears 3:1 against both surfaces.
- `--good` is `#0F7A4B` in light mode rather than the catalog's `#1B6746`, which
  falls below the chroma floor at status-dot size (it reads gray). Status colours
  are the catalog's own `--amber` and `--magenta` otherwise, and every status
  mark carries its word ("Good" / "Needs work" / "Poor"), so colour is never the
  only signal.

Light and dark both ship. The theme follows the OS by default; the toggle in the
masthead overrides it and remembers the choice in `localStorage`. There is no
`[data-theme="light"]` block on purpose — the dark media query is guarded with
`:not([data-theme="light"])`, so a light stamp already wins over OS dark.

## Layout

```
bin/        CLI: install, scan, worker, selftest
config/     defaults, overridden by .env
db/         schema.sql
public/     document root - one file per screen, plus api/queue.php and
            assets/ (theme.css = tokens, app.css = components, charts.js)
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
