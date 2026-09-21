# Mobile Web Audit

Core Web Vitals tracking for the sites we run SEO and paid ads on. Import a
site's sitemap, pick the pages worth tracking, score them on **mobile** through
Google's PageSpeed Insights API, keep every score as history you can graph, and
open a task automatically whenever a page drops below the target score.

Plain PHP 8 + MySQL — no framework, no Composer, no CDN.

**The tool lives in [`web-audit/`](web-audit/). See
[web-audit/README.md](web-audit/README.md) for install, usage and cron setup.**

## Quick start

```bash
mysql -u root -p -e "CREATE DATABASE web_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p web_audit < web-audit/db/schema.sql

cp web-audit/.env.example web-audit/.env     # set DB_* and PSI_API_KEY
```

Then run it:

```bash
cd web-audit
php bin/doctor.php                    # confirms PHP, MySQL, schema and API key
php -S 127.0.0.1:8000 -t public       # open http://127.0.0.1:8000
```

For a real deployment, point the web server's document root at
`web-audit/public`. Nothing outside `public/` should be web-reachable.
