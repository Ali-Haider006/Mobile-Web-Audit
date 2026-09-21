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
    'score_threshold' => 80,
    'app_password'    => '',
    'app_timezone'    => 'UTC',
    'http_timeout'    => 120,
    // Hard ceiling on how many URLs one sitemap import may pull in.
    'max_sitemap_urls' => 2000,
];
