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

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
