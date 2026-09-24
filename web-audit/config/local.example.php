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

    // ClickUp personal API token (ClickUp -> Settings -> Apps).
    'clickup_token' => '',

    // Required once this is reachable from the internet.
    'app_password' => '',

    // Shows the real error instead of a blank page. Turn it on while setting
    // up, then OFF: on a live site it prints notices to visitors, which leaks
    // absolute paths and breaks any redirect that follows the output.
    'debug' => false,

    'score_threshold' => 80,

    /*
     * Seconds to let Google take over one audit. Leave this alone unless an
     * audit reports a timeout: the app already fits the call inside the host's
     * max_execution_time by itself, and a low value here caps every audit
     * regardless of how much room the server actually has. A real page needs
     * 20-60s, so a 20 here fails almost everything.
     */
    // 'http_timeout' => 120,
];
