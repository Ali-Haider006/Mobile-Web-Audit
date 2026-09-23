<?php
declare(strict_types=1);

namespace Wva;

use PDO;
use Throwable;

/**
 * Environment checks, as data. bin/doctor.php renders them for the terminal;
 * public/setup.php renders the same list in the browser, which is the only
 * option on free hosting where there is no SSH.
 */
final class Doctor
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public const TABLES = ['sites', 'pages', 'exclusion_rules', 'audits', 'scan_runs', 'scan_items', 'tasks', 'settings'];

    /**
     * @param bool $liveApiCall spend one PageSpeed request proving the key works
     * @param bool $public      the app is reachable from the internet, so judge
     *                          the login gate as a real requirement
     * @return array<int,array{section:string,status:string,label:string,detail:string,remedy:string}>
     */
    public static function run(bool $liveApiCall = false, bool $public = false): array
    {
        $checks = [];

        $add = static function (array &$checks, string $section, string $status, string $label, string $detail = '', string $remedy = ''): void {
            $checks[] = compact('section', 'status', 'label', 'detail', 'remedy');
        };

        // ---- PHP -----------------------------------------------------------
        $add($checks, 'PHP', version_compare(PHP_VERSION, '8.0.0', '>=') ? self::OK : self::FAIL,
            'PHP ' . PHP_VERSION,
            'running as ' . PHP_SAPI,
            'This tool needs PHP 8.0 or newer. On shared hosting the PHP version is usually a dropdown in the control panel - set it to 8.1 or newer.');

        foreach (['pdo_mysql', 'curl', 'simplexml', 'mbstring', 'json'] as $extension) {
            $add($checks, 'PHP', extension_loaded($extension) ? self::OK : self::FAIL,
                'Extension ' . $extension,
                '',
                'Enable extension=' . $extension . ' in php.ini, or turn it on in your host\'s PHP settings.');
        }

        // A PageSpeed call takes 10-40s, so a short limit kills scans mid-flight.
        $limit = (int) ini_get('max_execution_time');
        if ($limit === 0) {
            $add($checks, 'PHP', self::OK, 'Script time limit', 'unlimited');
        } elseif ($limit >= 120) {
            $add($checks, 'PHP', self::OK, 'Script time limit', $limit . 's');
        } elseif ($limit >= 60) {
            $add($checks, 'PHP', self::WARN, 'Script time limit', $limit . 's',
                'A slow page can take 40s+ to audit. Scans will mostly work, but expect the odd timeout, which the queue retries.');
        } else {
            $add($checks, 'PHP', self::WARN, 'Script time limit', $limit . 's - short',
                'One audit can take longer than this. The queue records a timeout and retries, so scans still finish, just noisier. Raise max_execution_time to 120 if your host allows it.');
        }

        $memory = (string) ini_get('memory_limit');
        $add($checks, 'PHP', self::OK, 'Memory limit', $memory === '' ? 'default' : $memory);

        // ---- Config --------------------------------------------------------
        $envPath   = WVA_ROOT . '/.env';
        $localPath = WVA_ROOT . '/config/local.php';
        $settingsFileName = is_readable($localPath) ? 'config/local.php' : '.env';
        $sources   = [];
        if (is_readable($localPath)) {
            $sources[] = 'config/local.php';
        }
        if (is_readable($envPath)) {
            $sources[] = '.env';
        }
        $add($checks, 'Config', $sources === [] ? self::FAIL : self::OK,
            'Settings file',
            $sources === [] ? 'none found' : implode(' + ', $sources) . '  (app root: ' . WVA_ROOT . ')',
            'Create config/local.php (copy config/local.example.php) or .env, and fill in the database details.');

        // Name the file the operator actually used, not the one we happen to
        // document - "check .env" is useless advice to someone using local.php.
        $settingsFile = $settingsFileName;
        $isLocal      = $settingsFile === 'config/local.php';
        $keyName      = static fn (string $env, string $local): string => $isLocal ? $local : $env;

        // Secrets are reported by source, never echoed in full.
        foreach ([
            ['label' => 'PageSpeed API key', 'key' => 'psi_api_key', 'env' => 'PSI_API_KEY',
             'missing' => 'Scans still run, but Google rate-limits unkeyed requests hard. Add PSI_API_KEY to ' . $settingsFileName . '.'],
            ['label' => 'ClickUp API token', 'key' => 'clickup_token', 'env' => 'CLICKUP_TOKEN',
             'missing' => 'Optional. Without it the ClickUp integration stays off. Add CLICKUP_TOKEN to ' . $settingsFileName . ' to turn it on.'],
        ] as $secret) {
            $fromFile   = trim((string) Config::get($secret['key'], ''));
            $fromDb     = Settings::storedOnly($secret['key']);

            if ($fromFile !== '') {
                $add($checks, 'Config', self::OK, $secret['label'],
                    self::mask($fromFile) . ' (from ' . $settingsFileName . ')');
                if ($fromDb !== '') {
                    $add($checks, 'Config', self::WARN, $secret['label'] . ' also in the database',
                        'a copy is stored in the settings table',
                        'The file value is the one in use. Delete the "' . $secret['key'] . '" row from the settings table so the secret is not kept in two places.');
                }
            } elseif ($fromDb !== '') {
                $add($checks, 'Config', self::WARN, $secret['label'], self::mask($fromDb) . ' (from the database)',
                    'Secrets belong in ' . $settingsFileName . ' now. Add ' . $secret['env'] . ' there, then delete the "' . $secret['key'] . '" row from the settings table.');
            } else {
                $add($checks, 'Config', self::WARN, $secret['label'], 'not set', $secret['missing']);
            }
        }

        // A public deployment with no login is the one thing that must not ship.
        if (Auth::enabled()) {
            $add($checks, 'Config', self::OK, 'Login gate', 'enabled');
        } else {
            $setting = $keyName('APP_PASSWORD', "'app_password'");
            $add($checks, 'Config', $public ? self::FAIL : self::WARN, 'Login gate', 'no password set',
                $public
                    ? 'This app is reachable from the internet with no password. Anyone who finds the URL can read your clients\' data, add sites and spend your API quota. Set ' . $setting . ' in ' . $settingsFile . ' before sharing the link.'
                    : 'Fine behind a VPN or on your own machine. Set ' . $setting . ' in ' . $settingsFile . ' before putting this on the public internet.');
        }

        // ---- Database ------------------------------------------------------
        $pdo = null;
        try {
            $pdo = Database::pdo();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $add($checks, 'Database', self::OK, 'Connection',
                (string) Config::get('db_name') . '@' . (string) Config::get('db_host') . ' - ' . $version);
        } catch (Throwable $e) {
            $keys = $isLocal
                ? "'db_host', 'db_name', 'db_user' and 'db_pass'"
                : 'DB_HOST, DB_NAME, DB_USER and DB_PASS';
            $hint = str_contains($e->getMessage(), 'using password: NO')
                ? ' The password is currently empty, so these are still the example values.'
                : '';
            $add($checks, 'Database', self::FAIL, 'Connection', $e->getMessage(),
                'Set ' . $keys . ' in ' . $settingsFile . ' to the values from your host\'s control panel.' . $hint
                . ' Shared hosts rarely use 127.0.0.1 - they give you a hostname like sqlXXX.yourhost.com, and the database and user names are usually prefixed with your account id.');
        }

        if ($pdo !== null) {
            try {
                $present = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
                $missing = array_values(array_diff(self::TABLES, $present));
                if ($missing === []) {
                    $counts = Database::one(
                        'SELECT (SELECT COUNT(*) FROM sites) AS sites,
                                (SELECT COUNT(*) FROM pages) AS pages,
                                (SELECT COUNT(*) FROM audits) AS audits'
                    ) ?? [];
                    $add($checks, 'Database', self::OK, 'Schema',
                        count(self::TABLES) . ' tables - ' . (int) ($counts['sites'] ?? 0) . ' site(s), '
                        . (int) ($counts['pages'] ?? 0) . ' page(s), ' . (int) ($counts['audits'] ?? 0) . ' audit(s)');
                } else {
                    $add($checks, 'Database', self::FAIL, 'Schema', 'missing: ' . implode(', ', $missing),
                        'Load db/schema.sql. With SSH: php bin/install.php. Without SSH: open phpMyAdmin, pick the database, Import, choose db/schema.sql. Or use the button below.');
                }
            } catch (Throwable $e) {
                $add($checks, 'Database', self::FAIL, 'Schema', $e->getMessage(), 'Load db/schema.sql into the database.');
            }
        }

        // ---- Network -------------------------------------------------------
        if ($liveApiCall) {
            try {
                $url = PageSpeed::ENDPOINT . '?url=' . rawurlencode('https://example.com')
                    . '&strategy=mobile&category=PERFORMANCE'
                    . ($key !== '' ? '&key=' . rawurlencode($key) : '');
                // Same budget as a real audit: a shared host's front end cuts
                // long requests, and a cut connection tells us nothing.
                $limit   = (int) ini_get('max_execution_time');
                $timeout = $limit > 0 ? max(10, min(60, $limit - 12)) : 60;
                $response = Http::get($url, $timeout, 1);
                $payload  = json_decode($response['body'], true);

                if (isset($payload['error'])) {
                    $add($checks, 'Network', self::FAIL, 'PageSpeed API',
                        'rejected: ' . (string) $payload['error']['message'],
                        'Check the key is correct and that "PageSpeed Insights API" is enabled for its Google Cloud project.');
                } elseif ($response['status'] === 200) {
                    $score = $payload['lighthouseResult']['categories']['performance']['score'] ?? null;
                    $add($checks, 'Network', self::OK, 'PageSpeed API',
                        'example.com scored ' . ($score === null ? 'n/a' : (int) round(((float) $score) * 100)) . ' on mobile');
                } else {
                    $add($checks, 'Network', self::FAIL, 'PageSpeed API', 'HTTP ' . $response['status'],
                        'Check outbound HTTPS is allowed and the key is valid.');
                }
            } catch (Throwable $e) {
                $add($checks, 'Network', self::FAIL, 'Outbound HTTPS', $e->getMessage(),
                    'This host appears to block outbound connections from PHP, which this tool cannot work without - every audit is an API call to googleapis.com. Some free hosts block this. If so, you need a different host.');
            }
        } else {
            $add($checks, 'Network', self::WARN, 'PageSpeed API', 'not tested',
                'Run the test to spend one API request proving outbound HTTPS and the key both work. Worth doing first on any new host.');
        }

        return $checks;
    }

    /** Show enough of a secret to recognise it, never enough to use it. */
    public static function mask(string $secret): string
    {
        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }
        return substr($secret, 0, 6) . str_repeat('*', min(18, strlen($secret) - 6));
    }

    /** @param array<int,array<string,string>> $checks */
    public static function counts(array $checks): array
    {
        $totals = [self::OK => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($checks as $check) {
            $totals[$check['status']]++;
        }
        return $totals;
    }

    /**
     * Load db/schema.sql. Shared by the CLI installer and the web installer.
     *
     * @return array{applied:int, failed:int, errors:array<int,string>, missing:array<int,string>}
     */
    public static function installSchema(): array
    {
        $sql = (string) file_get_contents(WVA_ROOT . '/db/schema.sql');
        $sql = preg_replace('~^\s*--[^\n]*$~m', '', $sql) ?? $sql;
        $statements = array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []),
            static fn (string $statement): bool => $statement !== ''
        ));

        $pdo     = Database::pdo();
        $applied = 0;
        $errors  = [];
        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $applied++;
            } catch (Throwable $e) {
                $errors[] = trim(explode("\n", $statement)[0]) . ' - ' . $e->getMessage();
            }
        }

        // Existing databases need columns added that CREATE TABLE IF NOT EXISTS
        // will never apply.
        $migration = Migrations::run();
        foreach ($migration['errors'] as $error) {
            $errors[] = $error;
        }

        $present = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

        return [
            'applied'  => $applied,
            'failed'   => count($errors),
            'errors'   => $errors,
            'missing'  => array_values(array_diff(self::TABLES, $present)),
            'migrated' => $migration['applied'],
        ];
    }
}
