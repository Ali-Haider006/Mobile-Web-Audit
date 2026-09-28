<?php
/**
 * Defaults for the app. Anything here can be overridden in .env (same keys).
 * Runtime-editable values (API key, threshold) live in the `settings` table
 * and win over both - see Wva\Settings.
 */
return [
    'db_host'         => '127.0.0.1',
    'db_port'         => '3306',
    'db_name'         => 'web_audit',
    'db_user'         => 'root',
    'db_pass'         => '',
    'psi_api_key'     => '',
    'clickup_token'   => '',
    'score_threshold' => 80,
    /*
     * Hours between scheduled sweeps, enforced by cron.php. 84 is twice a
     * week. It exists because cron.php is meant to be polled far more often
     * than the audit schedule - the extra calls finish a long queue - so
     * something has to say when a NEW sweep is due. Overridable in Settings.
     */
    'audit_interval_hours' => 84,
    'app_password'    => '',
    // Set to a long random string to enable cron.php, the URL-triggered
    // scheduled run. Empty means that endpoint answers 404.
    'cron_key'        => '',
    'app_timezone'    => 'UTC',
    // Set 'debug' => true in config/local.php to show errors on the page.
    'debug'           => false,
    'http_timeout'    => 120,
    // Hard ceiling on how many URLs one sitemap import may pull in.
    'max_sitemap_urls' => 2000,
];
