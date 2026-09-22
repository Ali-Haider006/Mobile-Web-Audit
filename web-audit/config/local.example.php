<?php
/**
 * Copy to config/local.php and fill in. Use this instead of .env on shared
 * hosting: PHP files are executed, never served as text, so these values
 * cannot be downloaded even if the file sits inside the web root.
 */
return [
    'db_host' => 'sqlXXX.infinityfree.com',
    'db_port' => '3306',
    'db_name' => 'if0_00000000_webaudit',
    'db_user' => 'if0_00000000',
    'db_pass' => '',

    'psi_api_key' => '',

    // Required once this is reachable from the internet.
    'app_password' => '',

    // Turn on while setting up: shows the real error instead of a blank page.
    'debug' => true,

    'score_threshold' => 80,
    // Shared hosts often cap scripts at ~30s; keep the API call inside that.
    'http_timeout'    => 20,
];
