<?php
declare(strict_types=1);

/**
 * Offline checks for the parsing logic - no database, no network.
 *   php bin/selftest.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\ClickUp;
use Wva\Doctor;
use Wva\Helpers;
use Wva\PageSpeed;
use Wva\Sitemap;

$passed = 0;
$failed = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  ok   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label\n       expected: " . var_export($expected, true)
        . "\n       actual:   " . var_export($actual, true) . "\n";
}

echo "URL normalisation\n";
check('adds scheme', Helpers::normalizeUrl('example.com/page'), 'https://example.com/page');
check('lowercases host', Helpers::normalizeUrl('HTTPS://Example.COM/Page'), 'https://example.com/Page');
check('drops trailing slash', Helpers::normalizeUrl('https://example.com/a/b/'), 'https://example.com/a/b');
check('keeps root slash', Helpers::normalizeUrl('https://example.com'), 'https://example.com/');
check('drops fragment', Helpers::normalizeUrl('https://example.com/a#top'), 'https://example.com/a');
check('keeps query', Helpers::normalizeUrl('https://example.com/a?b=1'), 'https://example.com/a?b=1');
check('drops default port', Helpers::normalizeUrl('https://example.com:443/a'), 'https://example.com/a');
check('rejects mailto', Helpers::normalizeUrl('mailto:hi@example.com'), null);
check('rejects empty', Helpers::normalizeUrl('   '), null);
check('rejects prose', Helpers::normalizeUrl('not a url'), null);
check('rejects bare word', Helpers::normalizeUrl('homepage'), null);
check('encodes spaces in path', Helpers::normalizeUrl('https://example.com/my page.html'), 'https://example.com/my%20page.html');
check('accepts ipv4 host', Helpers::normalizeUrl('http://192.168.1.10/status'), 'http://192.168.1.10/status');
check('accepts hyphenated host', Helpers::normalizeUrl('https://my-client.co.uk'), 'https://my-client.co.uk/');

echo "Exclusion patterns\n";
check('wildcard middle', Helpers::matchesPattern('https://x.com/tag/seo', '*/tag/*'), true);
check('no false match', Helpers::matchesPattern('https://x.com/blog/seo', '*/tag/*'), false);
check('query pattern', Helpers::matchesPattern('https://x.com/?s=hello', '*?s=*'), true);
check('empty pattern', Helpers::matchesPattern('https://x.com/', ''), false);

echo "Sitemap parsing\n";
$urlset = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/</loc><lastmod>2026-01-15</lastmod></url>
  <url><loc>https://example.com/services</loc></url>
</urlset>
XML;
$parsed = Sitemap::parse($urlset);
check('urlset count', count($parsed['urls']), 2);
check('first loc', $parsed['urls'][0]['loc'], 'https://example.com/');
check('lastmod parsed', $parsed['urls'][0]['lastmod'], '2026-01-15');
check('missing lastmod', $parsed['urls'][1]['lastmod'], null);

$index = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>
  <sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>
</sitemapindex>
XML;
$parsedIndex = Sitemap::parse($index);
check('index children', count($parsedIndex['sitemaps']), 2);
check('index has no urls', $parsedIndex['urls'], []);

$text = "https://example.com/a\nhttps://example.com/b\nnot-a-url\n";
check('plain text sitemap', count(Sitemap::parse($text)['urls']), 2);
check('garbage input', Sitemap::parse('<html><body>404</body></html>')['urls'], []);

echo "Origin\n";
check('origin strips path', Sitemap::origin('https://example.com/a/b?c=1'), 'https://example.com');

echo "PageSpeed payload parsing\n";
$payload = [
    'lighthouseResult' => [
        'lighthouseVersion' => '11.0.0',
        'fetchTime'         => '2026-09-20T10:11:12.000Z',
        'categories' => [
            'performance'    => ['score' => 0.25],
            'accessibility'  => ['score' => 0.88],
            'best-practices' => ['score' => 1],
            'seo'            => ['score' => 0.92],
        ],
        'audits' => [
            'largest-contentful-paint' => ['numericValue' => 6421.4, 'score' => 0.1],
            'first-contentful-paint'   => ['numericValue' => 2100.0, 'score' => 0.4],
            'cumulative-layout-shift'  => ['numericValue' => 0.2345, 'score' => 0.3],
            'total-blocking-time'      => ['numericValue' => 890.0, 'score' => 0.05],
            'speed-index'              => ['numericValue' => 5300.0, 'score' => 0.2],
            'server-response-time'     => ['numericValue' => 780.0, 'score' => 0.3],
            'unused-javascript'        => [
                'title' => 'Reduce unused JavaScript',
                'score' => 0.2,
                'displayValue' => 'Potential savings of 420 KiB',
                'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1800],
            ],
            'render-blocking-resources' => [
                'title' => 'Eliminate render-blocking resources',
                'score' => 0.3,
                'details' => ['type' => 'opportunity', 'overallSavingsMs' => 950],
            ],
            'uses-long-cache-ttl' => [
                'title' => 'Serve static assets with an efficient cache policy',
                'score' => 0.5,
                'details' => ['type' => 'table'],
            ],
        ],
    ],
    'loadingExperience' => [
        'overall_category' => 'SLOW',
        'metrics' => [
            'LARGEST_CONTENTFUL_PAINT_MS'   => ['percentile' => 4800],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 18],
            'INTERACTION_TO_NEXT_PAINT'     => ['percentile' => 340],
        ],
    ],
];
$row = PageSpeed::parse($payload);
check('performance score', $row['performance_score'], 25);
check('seo score', $row['seo_score'], 92);
check('best practices 100', $row['best_practices_score'], 100);
check('lcp rounded', $row['lcp_ms'], 6421);
check('cls kept', $row['cls'], 0.235);
check('tbt', $row['tbt_ms'], 890);
check('ttfb', $row['ttfb_ms'], 780);
check('field lcp', $row['field_lcp_ms'], 4800);
check('field cls scaled', $row['field_cls'], 0.18);
check('field inp', $row['field_inp_ms'], 340);
check('field verdict', $row['field_verdict'], 'SLOW');
check('fetch time utc', $row['fetched_at'], '2026-09-20 10:11:12');
check('lighthouse version', $row['lighthouse_version'], '11.0.0');
check('opportunities ranked', $row['opportunities'][0]['id'], 'unused-javascript');
check('opportunity count', count($row['opportunities']), 2);
check('savings kept', $row['opportunities'][0]['savings_ms'], 1800);

echo "Empty payload is survivable\n";
$empty = PageSpeed::parse([]);
check('no score', $empty['performance_score'], null);
check('no opportunities', $empty['opportunities'], []);

echo "Formatting\n";
check('ms under a second', Helpers::ms(840), '840 ms');
check('seconds', Helpers::ms(2540), '2.54 s');
check('null metric', Helpers::ms(null), '—');
check('cls format', Helpers::cls(0.1), '0.100');
check('band good', Helpers::scoreBand(90), 'good');
check('band average', Helpers::scoreBand(80), 'average');
check('band poor', Helpers::scoreBand(25), 'poor');
check('band none', Helpers::scoreBand(null), 'none');


echo "ClickUp member parsing\n";
$teamPayload = ['teams' => [[
    'id' => '9001', 'name' => 'Pixelchefs',
    'members' => [
        ['user' => ['id' => 501, 'username' => 'Zoe Ray', 'email' => 'zoe@example.com']],
        ['user' => ['id' => 502, 'username' => 'Ali Haider', 'email' => 'ali@example.com']],
        ['user' => ['id' => 503, 'username' => '', 'email' => 'invited@example.com']],
        ['user' => ['username' => 'no id']],
        ['nonsense'],
    ],
]]];
$people = ClickUp::parseMembers($teamPayload);
check('members parsed', count($people), 3);
check('sorted by name', $people[0]['name'], 'Ali Haider');
$byId = array_column($people, 'name', 'id');
check('invited user falls back to email', $byId[503] ?? null, 'invited@example.com');
check('ids are integers', $people[0]['id'], 502);
check('a member without an id is dropped', isset($byId[0]), false);
check('no members key is survivable', ClickUp::parseMembers(['teams' => [['id' => '1', 'name' => 'x']]]), []);
check('error payload yields nobody', ClickUp::parseMembers(['err' => 'Token invalid']), []);

echo "ClickUp token owner\n";
$owner = ClickUp::parseTokenOwner(['user' => ['id' => 502, 'username' => 'Ali Haider', 'email' => 'ali@example.com']]);
check('owner id', $owner['id'] ?? null, 502);
check('owner name', $owner['name'] ?? null, 'Ali Haider');
check(
    'owner without a username falls back to email',
    ClickUp::parseTokenOwner(['user' => ['id' => 7, 'email' => 'a@b.c']])['name'] ?? null,
    'a@b.c'
);
check('no user key means nobody', ClickUp::parseTokenOwner(['err' => 'Token invalid']), null);
check('a user without an id means nobody', ClickUp::parseTokenOwner(['user' => ['username' => 'x']]), null);
check('id zero means nobody', ClickUp::parseTokenOwner(['user' => ['id' => 0, 'username' => 'x']]), null);

echo "PHP 8.5 and locked-down hosts\n";
$sources = [];
foreach (['src', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WVA_ROOT . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $sources[str_replace(WVA_ROOT . '/', '', $f->getPathname())] = file_get_contents($f->getPathname());
        }
    }
}

// curl_close() is deprecated in 8.5 and has done nothing since 8.0. The notice
// printed before redirect headers, which broke the page outright.
$closers = [];
foreach ($sources as $file => $code) {
    foreach (explode("\n", $code) as $n => $line) {
        $trimmed = ltrim($line);
        // Skip comment lines - the ban is explained in one.
        if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }
        // Drop a trailing comment too - one of them names the banned call.
        $code_only = (string) preg_replace('~//.*$~', '', $line);
        if (str_contains($code_only, 'curl_close')) {
            $closers[] = $file . ':' . ($n + 1);
        }
    }
}
check('nothing calls curl_close()', $closers, []);

// set_time_limit in disable_functions makes the call a fatal Error, which @
// does not suppress - so every call site must be guarded.
$unguarded = [];
foreach ($sources as $file => $code) {
    $lines = explode("\n", $code);
    foreach ($lines as $n => $line) {
        if (!preg_match('/^\s*@?set_time_limit\s*\(/', $line)) {
            continue;
        }
        $before = implode("\n", array_slice($lines, max(0, $n - 4), 4));
        if (!str_contains($before, "function_exists('set_time_limit')")) {
            $unguarded[] = $file . ':' . ($n + 1);
        }
    }
}
check('every set_time_limit() is guarded', $unguarded, []);

// A redirect must survive output that has already been sent.
check('redirect checks headers_sent()', str_contains($sources['src/Helpers.php'] ?? '', 'headers_sent()'), true);
check('and falls back to a meta refresh', str_contains($sources['src/Helpers.php'] ?? '', 'http-equiv="refresh"'), true);
check('notices are hidden unless debug is on', str_contains($sources['public/_init.php'] ?? '', "ini_set('display_errors', '0')"), true);

// The timeout message has to name the setting that produced the number.
$explain = new ReflectionMethod(\Wva\PageSpeed::class, 'explainTimeout');
$explain->setAccessible(true);
$timedOut = $explain->invoke(null, 'Operation timed out after 20002 milliseconds', 20, 32, false);
check('the timeout message names max_execution_time', str_contains($timedOut, 'max_execution_time of 32'), true);
check('and says how to raise it', str_contains($timedOut, 'Raise max_execution_time'), true);
check(
    'a non-timeout error passes through unchanged',
    $explain->invoke(null, 'PageSpeed API error: quota exceeded', 20, 32, false),
    'PageSpeed API error: quota exceeded'
);

echo "Cron endpoint\n";
$cron = file_get_contents(WVA_ROOT . '/public/cron.php');
check('it is a public page, not behind the login gate', str_contains($cron, "define('WVA_PUBLIC_PAGE', true)"), true);
check('the key is compared in constant time', str_contains($cron, 'hash_equals('), true);
check('an unset key disables it', str_contains($cron, '$configured === \'\' || !hash_equals'), true);
check('off and wrong look the same', substr_count($cron, 'http_response_code(404)'), 1);
check('it refuses to run against an old schema', str_contains($cron, 'Migrations::pending()'), true);
check('it works to a time budget', str_contains($cron, '$budget'), true);
check('and resumes what it could not finish', str_contains($cron, 'Runs::unfinished()'), true);
check('cron_key ships empty', (string) (require WVA_ROOT . '/config/config.php')['cron_key'], '');

echo "Deployment package\n";
$zipPath = WVA_ROOT . '/dist/mobile-web-audit-flat.zip';
@unlink($zipPath);
$pkg = (string) shell_exec('php ' . escapeshellarg(WVA_ROOT . '/bin/package.php') . ' --flat 2>&1');
check('the flat package builds', is_file($zipPath), true);
check('and says which layout it built', str_contains($pkg, 'flat'), true);
if (is_file($zipPath)) {
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    check('no credentials are packed', in_array('config/local.php', $names, true) || in_array('.env', $names, true), false);
    check('no test suites are packed', in_array('bin/selftest.php', $names, true), false);
    check('the entry point is at the top', in_array('index.php', $names, true), true);
    check('src stays in its folder', in_array('src/Monitor.php', $names, true), true);
    check('the guards come with it', in_array('src/.htaccess', $names, true), true);
    check('one .htaccess at the root, merged', count(array_keys($names, '.htaccess')), 1);
    $merged = null;
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $merged = $zip->getFromName('.htaccess');
    $zip->close();
    check('the merge keeps the deny rules', str_contains((string) $merged, 'FilesMatch'), true);
    check('and the security headers', str_contains((string) $merged, 'X-Frame-Options'), true);
    unlink($zipPath);                       // build output, not a fixture
    @rmdir(WVA_ROOT . '/dist');
}

echo "Secret masking\n";
check('short secrets are fully hidden', Doctor::mask('abc'), '***');
check('long secrets keep 6 characters', str_starts_with(Doctor::mask('pk_98765_SECRETVALUE'), 'pk_987'), true);
check('and reveal nothing after that', str_contains(Doctor::mask('pk_98765_SECRETVALUE'), 'SECRET'), false);

echo "ClickUp payload parsing\n";

$teams = ['teams' => [
    ['id' => '9001', 'name' => 'Pixelchefs'],
    ['id' => '9002'],                               // no name - must be skipped
    'nonsense',                                     // not even an array
]];
check('parseNamed keeps complete rows', count(ClickUp::parseNamed($teams, 'teams')), 1);
check('parseNamed on a missing key', ClickUp::parseNamed(['x' => 1], 'teams'), []);
check('parseNamed on an error body', ClickUp::parseNamed(['err' => 'Token invalid'], 'teams'), []);

check('created task id', ClickUp::parseCreated(['id' => 'abc123', 'url' => 'https://app.clickup.com/t/abc123'])['id'], 'abc123');
check('created task url', ClickUp::parseCreated(['id' => 'abc123'])['url'], 'https://app.clickup.com/t/abc123');
check('created with no id', ClickUp::parseCreated([])['id'], '');

check('401 names the token', str_contains(ClickUp::errorMessage(['err' => 'Token invalid', 'ECODE' => 'OAUTH_025'], 401), 'check the API token'), true);
check('429 names the rate limit', str_contains(ClickUp::errorMessage(['err' => 'Rate limit'], 429), 'rate limit'), true);
check('error keeps ClickUp code', str_contains(ClickUp::errorMessage(['err' => 'No', 'ECODE' => 'X1'], 400), '(X1)'), true);
check('error with empty body', ClickUp::errorMessage([], 500), 'ClickUp: HTTP 500');

check('critical maps to urgent', ClickUp::priorityFor('critical'), 1);
check('high maps to high', ClickUp::priorityFor('high'), 2);
check('anything else is normal', ClickUp::priorityFor('normal'), 3);

/**
 * The stylesheet split only stays honest if something checks it, so these run
 * with the unit tests: the theme layer owns every colour, and the component
 * layer and charts.js may only read tokens the theme actually defines.
 */
echo "Stylesheet layering\n";

$themeCss  = (string) file_get_contents(WVA_ROOT . '/public/assets/theme.css');
$appCss    = (string) file_get_contents(WVA_ROOT . '/public/assets/app.css');
$chartsJs  = (string) file_get_contents(WVA_ROOT . '/public/assets/charts.js');

/** @return array<string,string> token => value, for one CSS block */
function blockTokens(string $css, string $selector): array
{
    $start = strpos($css, $selector);
    if ($start === false) {
        return [];
    }
    $open  = strpos($css, '{', $start);
    $close = strpos($css, '}', $open);
    $body  = substr($css, $open + 1, $close - $open - 1);
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $match) {
        $out[$match[1]] = trim($match[2]);
    }
    return $out;
}

$light    = blockTokens($themeCss, ':root {');
$osDark   = blockTokens($themeCss, ':root:not([data-theme="light"]) {');
$attrDark = blockTokens($themeCss, ':root[data-theme="dark"] {');

check('theme defines light tokens', count($light) > 15, true);
check('OS-dark block is not empty', count($osDark) > 10, true);
// The two dark blocks are duplicates by design - if they drift, one theme path
// silently gets stale colours.
check('both dark blocks define the same tokens', array_keys($osDark) === array_keys($attrDark), true);
check('both dark blocks agree on values', $osDark === $attrDark, true);
check('no dark token is missing from light', array_diff(array_keys($osDark), array_keys($light)), []);

// Component layer must not hard-code anything visual.
$appNoComments = (string) preg_replace('~/\*.*?\*/~s', '', $appCss);
preg_match_all('/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $appNoComments, $literals);
check('app.css declares no raw colours', $literals[0], []);

// Every token read must exist, in both layers that read them.
preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $appNoComments, $used);
$undefinedInCss = array_values(array_unique(array_diff($used[1], array_keys($light))));
check('every var() in app.css is defined', $undefinedInCss, []);

preg_match_all("/token\(\s*'(--[a-z0-9-]+)'/", $chartsJs, $jsUsed);
$undefinedInJs = array_values(array_unique(array_diff($jsUsed[1], array_keys($light))));
check('every token() in charts.js is defined', $undefinedInJs, []);
check('charts.js reads at least the series colour', in_array('--series-1', $jsUsed[1], true), true);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
