<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;

/**
 * Discovers and walks a site's sitemap(s).
 *
 * Handles: robots.txt discovery, the usual sitemap filenames, <sitemapindex>
 * nesting, gzipped sitemaps and plain-text sitemaps.
 */
final class Sitemap
{
    private const CANDIDATES = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/wp-sitemap.xml',
        '/sitemap/sitemap.xml',
        '/sitemap.xml.gz',
        '/sitemap.txt',
    ];

    /**
     * @return array{sitemaps:array<int,string>, urls:array<int,array{loc:string,lastmod:?string}>, notes:array<int,string>}
     */
    public static function crawl(string $siteUrl, ?string $explicitSitemap = null, int $maxUrls = 2000, int $timeout = 30): array
    {
        $base = Helpers::normalizeUrl($siteUrl);
        if ($base === null) {
            throw new RuntimeException('That does not look like a valid http(s) URL.');
        }

        $notes   = [];
        $queue   = [];
        $visited = [];
        $urls    = [];

        if ($explicitSitemap !== null && trim($explicitSitemap) !== '') {
            $queue[] = trim($explicitSitemap);
        } else {
            $fromRobots = self::fromRobots($base, $timeout);
            if ($fromRobots !== []) {
                $notes[] = 'Found ' . count($fromRobots) . ' sitemap reference(s) in robots.txt.';
                $queue   = $fromRobots;
            } else {
                $origin = self::origin($base);
                foreach (self::CANDIDATES as $candidate) {
                    $queue[] = $origin . $candidate;
                }
                $notes[] = 'robots.txt listed no sitemap - trying the usual filenames.';
            }
        }

        $sitemapsUsed = [];
        $guard        = 0;

        while ($queue !== [] && count($urls) < $maxUrls && $guard < 60) {
            $guard++;
            $current = array_shift($queue);
            if ($current === null || isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            try {
                $response = Http::get($current, $timeout, 2);
            } catch (RuntimeException $e) {
                continue;
            }
            if ($response['status'] !== 200 || trim($response['body']) === '') {
                continue;
            }

            $body = self::maybeGunzip($response['body']);
            $parsed = self::parse($body);
            if ($parsed['urls'] === [] && $parsed['sitemaps'] === []) {
                continue;
            }

            $sitemapsUsed[] = $current;
            foreach ($parsed['sitemaps'] as $child) {
                if (!isset($visited[$child])) {
                    $queue[] = $child;
                }
            }
            foreach ($parsed['urls'] as $entry) {
                if (count($urls) >= $maxUrls) {
                    $notes[] = 'Stopped at the ' . $maxUrls . '-URL import limit.';
                    break 2;
                }
                $urls[] = $entry;
            }
        }

        if ($sitemapsUsed === []) {
            throw new RuntimeException(
                'No sitemap found for ' . $base . '. Enter the sitemap URL directly if it lives somewhere unusual.'
            );
        }

        // De-duplicate on the normalised URL, keeping the first lastmod seen.
        $unique = [];
        foreach ($urls as $entry) {
            $normalized = Helpers::normalizeUrl($entry['loc']);
            if ($normalized === null) {
                continue;
            }
            $unique[$normalized] ??= ['loc' => $normalized, 'lastmod' => $entry['lastmod']];
        }

        return [
            'sitemaps' => $sitemapsUsed,
            'urls'     => array_values($unique),
            'notes'    => $notes,
        ];
    }

    /**
     * Parse one sitemap document (XML index, XML urlset, or plain text).
     * Pure - handy for tests.
     *
     * @return array{sitemaps:array<int,string>, urls:array<int,array{loc:string,lastmod:?string}>}
     */
    public static function parse(string $body): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\n\r");
        $out  = ['sitemaps' => [], 'urls' => []];

        if ($body === '') {
            return $out;
        }

        if ($body[0] !== '<') {
            // Plain-text sitemap: one URL per line.
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && preg_match('~^https?://~i', $line)) {
                    $out['urls'][] = ['loc' => $line, 'lastmod' => null];
                }
            }
            return $out;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return $out;
        }

        $name = strtolower($xml->getName());
        if ($name === 'sitemapindex') {
            foreach ($xml->children() as $child) {
                $loc = trim((string) ($child->loc ?? ''));
                if ($loc !== '') {
                    $out['sitemaps'][] = $loc;
                }
            }
            return $out;
        }

        foreach ($xml->children() as $child) {
            if (strtolower($child->getName()) !== 'url') {
                continue;
            }
            $loc = trim((string) ($child->loc ?? ''));
            if ($loc === '') {
                continue;
            }
            $lastmod = trim((string) ($child->lastmod ?? ''));
            $date    = $lastmod !== '' ? strtotime($lastmod) : false;
            $out['urls'][] = [
                'loc'     => $loc,
                'lastmod' => $date === false ? null : gmdate('Y-m-d', $date),
            ];
        }

        return $out;
    }

    /** @return array<int,string> */
    private static function fromRobots(string $base, int $timeout): array
    {
        try {
            $response = Http::get(self::origin($base) . '/robots.txt', $timeout, 1);
        } catch (RuntimeException $e) {
            return [];
        }
        if ($response['status'] !== 200) {
            return [];
        }

        $found = [];
        foreach (preg_split('/\R/', $response['body']) ?: [] as $line) {
            if (preg_match('/^\s*sitemap\s*:\s*(\S+)/i', $line, $m)) {
                $found[] = trim($m[1]);
            }
        }
        return array_values(array_unique($found));
    }

    public static function origin(string $url): string
    {
        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host'] ?? '');
        $port   = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    private static function maybeGunzip(string $body): string
    {
        if (strncmp($body, "\x1f\x8b", 2) !== 0) {
            return $body;
        }
        $plain = @gzdecode($body);
        return $plain === false ? $body : $plain;
    }
}
