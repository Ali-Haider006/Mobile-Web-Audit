<?php
declare(strict_types=1);

/**
 * "My host gave me an IP, a hostname, a username and a password - now what?"
 *
 * Answers the one setting they never give you: the database name. Connects
 * with no database selected and reports what this login can actually see.
 *
 *   php bin/dbcheck.php
 *
 * Reads config/local.php / .env like everything else, so fill in db_host,
 * db_user and db_pass first and leave db_name alone. Read-only: it lists
 * databases and grants, it creates nothing.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only - the same report is on the Setup screen when a connection fails\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Config;
use Wva\Database;

$host = (string) Config::get('db_host', '127.0.0.1');
$user = (string) Config::get('db_user', '');
$name = (string) Config::get('db_name', '');

echo "Connecting to {$host} as {$user}...\n\n";

$probe = Database::probe();

if (!$probe['ok']) {
    echo "FAILED: {$probe['error']}\n\n";
    echo str_contains($probe['error'], 'Access denied')
        ? "The host is reachable but the username or password is wrong.\n"
        : "Could not reach the server at all. Check the hostname/IP and port, and whether\n"
          . "this machine is allowed to connect - a remote SQL server usually only accepts\n"
          . "connections from IPs on its allow-list.\n";
    exit(1);
}

echo "Connected. MySQL {$probe['version']}\n";
echo "So the host, username and password are all correct.\n\n";

if ($probe['databases'] === []) {
    echo "This login can see no databases.\n\n";
    echo $probe['can_create']
        ? "It is allowed to create one:\n\n"
            . "  CREATE DATABASE pixelchefs_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n\n"
            . "Then set 'db_name' to that name.\n"
        : "It is also not allowed to create one. Ask whoever runs the SQL server for a\n"
            . "database, and for this user to be granted access to it.\n";
    exit(1);
}

echo "Databases this login can see:\n";
foreach ($probe['databases'] as $db) {
    echo '  - ' . $db . ($db === $name ? "   <- your current 'db_name'" : '') . "\n";
}
echo "\n";

if ($name !== '' && in_array($name, $probe['databases'], true)) {
    echo "'db_name' is set to a database you can reach. Run: php bin/install.php\n";
    exit(0);
}

echo count($probe['databases']) === 1
    ? "Set 'db_name' to '" . $probe['databases'][0] . "' in config/local.php, then run: php bin/install.php\n"
    : "Set 'db_name' to whichever of these is yours, then run: php bin/install.php\n";
exit(1);
