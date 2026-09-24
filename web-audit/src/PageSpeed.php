<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;

/**
 * Google PageSpeed Insights v5 client - mobile strategy only, which is what
 * Google ranks on and what our audits are scored against.
 */
final class PageSpeed
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Seconds needed before asking Google for accessibility, best-practices and
     * SEO on top of performance. Below this, performance alone is requested.
     */
    private const FULL_CATEGORY_BUDGET = 45;

    /**
     * Run the API and return a normalised row ready for the `audits` table.
     *
     * @return array<string,mixed>
     */
    public static function audit(string $url, ?string $apiKey = null, int $timeout = 120): array
    {
        $apiKey ??= Settings::apiKey();
        $query = [
            'url'      => $url,
            'strategy' => 'mobile',
            'locale'   => 'en',
        ];
        /*
         * Shared hosts kill a script at max_execution_time, and a killed script
         * answers with an empty body - which the browser reports only as
         * "Unexpected end of JSON input". Fit the call inside the limit, and
         * drop HTTP-level retries when there is no room for them: the queue
         * retries the item anyway, so a retry here only spends the budget.
         *
         * Ask for more room first. Most hosts allow this even when they will
         * not let you edit php.ini, and a default 30s limit leaves ~20s, which
         * a real page rarely audits inside.
         */
        self::askForMoreTime();

        $limit    = (int) ini_get('max_execution_time');
        $attempts = 3;
        $budget   = 0;
        if ($limit > 0) {
            $budget   = max(10, $limit - 12);
            $timeout  = min($timeout, $budget);
            $attempts = 1;
        }

        /*
         * Four categories roughly triples how long Google takes. When there is
         * not room for that, ask for performance alone rather than time out:
         * a score is what the pass/fail rule needs, and the other three show
         * as "-" instead of the whole audit failing.
         */
        $full       = $budget === 0 || $budget >= self::FULL_CATEGORY_BUDGET;
        $categories = $full
            ? ['PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO']
            : ['PERFORMANCE'];

        $request = self::ENDPOINT . '?' . http_build_query($query);
        foreach ($categories as $category) {
            $request .= '&category=' . $category;
        }
        if ($apiKey !== '') {
            $request .= '&key=' . rawurlencode($apiKey);
        }

        $startedAt = microtime(true);
        try {
            $response = Http::get($request, $timeout, $attempts);
        } catch (RuntimeException $e) {
            throw new RuntimeException(self::explainTimeout($e->getMessage(), $timeout, $limit, $full));
        }
        $payload   = json_decode($response['body'], true);

        if (!is_array($payload)) {
            throw new RuntimeException('PageSpeed returned a response that is not JSON (HTTP ' . $response['status'] . ')');
        }
        if (isset($payload['error'])) {
            $message = (string) ($payload['error']['message'] ?? 'unknown API error');
            throw new RuntimeException('PageSpeed API error: ' . self::trim($message, 400));
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('PageSpeed API returned HTTP ' . $response['status']);
        }

        $row = self::parse($payload);
        $row['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $row;
    }

    /**
     * Pull the numbers we keep out of a PSI payload. Pure - no I/O - so it can
     * be tested against a saved response (see bin/selftest.php).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function parse(array $payload): array
    {
        $lighthouse = is_array($payload['lighthouseResult'] ?? null) ? $payload['lighthouseResult'] : [];
        $categories = is_array($lighthouse['categories'] ?? null) ? $lighthouse['categories'] : [];
        $audits     = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];

        $row = [
            'performance_score'    => self::score($categories, 'performance'),
            'accessibility_score'  => self::score($categories, 'accessibility'),
            'best_practices_score' => self::score($categories, 'best-practices'),
            'seo_score'            => self::score($categories, 'seo'),
            'lcp_ms'               => self::metricMs($audits, 'largest-contentful-paint'),
            'fcp_ms'               => self::metricMs($audits, 'first-contentful-paint'),
            'tbt_ms'               => self::metricMs($audits, 'total-blocking-time'),
            'si_ms'                => self::metricMs($audits, 'speed-index'),
            'ttfb_ms'              => self::metricMs($audits, 'server-response-time'),
            'cls'                  => self::metricRaw($audits, 'cumulative-layout-shift'),
            'lighthouse_version'   => isset($lighthouse['lighthouseVersion'])
                ? substr((string) $lighthouse['lighthouseVersion'], 0, 20)
                : null,
            'fetched_at'           => self::fetchTime($lighthouse),
        ];

        // CrUX field data - the real-user numbers Google reports in Search Console.
        $field = is_array($payload['loadingExperience'] ?? null) ? $payload['loadingExperience'] : [];
        $metrics = is_array($field['metrics'] ?? null) ? $field['metrics'] : [];
        $row['field_lcp_ms']  = self::fieldValue($metrics, 'LARGEST_CONTENTFUL_PAINT_MS');
        $row['field_inp_ms']  = self::fieldValue($metrics, 'INTERACTION_TO_NEXT_PAINT');
        $cls = self::fieldValue($metrics, 'CUMULATIVE_LAYOUT_SHIFT_SCORE');
        // CrUX reports CLS multiplied by 100.
        $row['field_cls']     = $cls === null ? null : round($cls / 100, 3);
        $row['field_verdict'] = isset($field['overall_category'])
            ? substr((string) $field['overall_category'], 0, 20)
            : null;

        $row['opportunities'] = self::opportunities($audits);

        return $row;
    }

    /**
     * The biggest wins Lighthouse found, newest-first by estimated saving.
     * These become the checklist inside an auto-created task.
     *
     * @param array<string,mixed> $audits
     * @return array<int,array{id:string,title:string,display:string,savings_ms:int}>
     */
    public static function opportunities(array $audits, int $limit = 6): array
    {
        $found = [];
        foreach ($audits as $id => $audit) {
            if (!is_array($audit)) {
                continue;
            }
            $score = $audit['score'] ?? null;
            if ($score === null || (float) $score >= 0.9) {
                continue;
            }
            $details = is_array($audit['details'] ?? null) ? $audit['details'] : [];
            $savings = 0;
            if (isset($details['overallSavingsMs'])) {
                $savings = (int) round((float) $details['overallSavingsMs']);
            }
            $mode = (string) ($details['type'] ?? '');
            if ($savings === 0 && $mode !== 'opportunity') {
                continue; // diagnostics without a measurable saving: skip the noise
            }
            $found[] = [
                'id'         => substr((string) $id, 0, 80),
                'title'      => self::trim((string) ($audit['title'] ?? $id), 160),
                'display'    => self::trim((string) ($audit['displayValue'] ?? ''), 80),
                'savings_ms' => $savings,
            ];
        }

        usort($found, static fn (array $a, array $b): int => $b['savings_ms'] <=> $a['savings_ms']);

        return array_slice($found, 0, $limit);
    }

    /** @param array<string,mixed> $categories */
    private static function score(array $categories, string $key): ?int
    {
        $score = $categories[$key]['score'] ?? null;
        if ($score === null || !is_numeric($score)) {
            return null;
        }
        return (int) round(((float) $score) * 100);
    }

    /** @param array<string,mixed> $audits */
    private static function metricMs(array $audits, string $key): ?int
    {
        $value = $audits[$key]['numericValue'] ?? null;
        return $value === null || !is_numeric($value) ? null : (int) round((float) $value);
    }

    /** @param array<string,mixed> $audits */
    private static function metricRaw(array $audits, string $key): ?float
    {
        $value = $audits[$key]['numericValue'] ?? null;
        return $value === null || !is_numeric($value) ? null : round((float) $value, 3);
    }

    /** @param array<string,mixed> $metrics */
    private static function fieldValue(array $metrics, string $key): ?int
    {
        $value = $metrics[$key]['percentile'] ?? null;
        return $value === null || !is_numeric($value) ? null : (int) round((float) $value);
    }

    /** @param array<string,mixed> $lighthouse */
    private static function fetchTime(array $lighthouse): string
    {
        $raw = (string) ($lighthouse['fetchTime'] ?? '');
        $ts  = $raw !== '' ? strtotime($raw) : false;
        return gmdate('Y-m-d H:i:s', $ts === false ? time() : $ts);
    }

    /**
     * Ask the host for more running time, where it is allowed to.
     *
     * function_exists() is not belt and braces here: a host that lists
     * set_time_limit in disable_functions turns the call into a fatal Error,
     * and the @ operator does not suppress an Error - so calling it blindly
     * kills every audit on exactly the locked-down hosts that need the
     * fallback below.
     */
    private static function askForMoreTime(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
    }

    /**
     * A bare "Operation timed out after 20002 milliseconds" tells nobody what
     * to change. Name the limit that produced that number and the two ways out.
     */
    private static function explainTimeout(string $message, int $timeout, int $limit, bool $full): string
    {
        if (!str_contains($message, 'timed out')) {
            return $message;
        }

        $why = $limit > 0
            ? ' The ' . $timeout . 's ceiling comes from this server\'s max_execution_time of ' . $limit
                . 's, less headroom to return a reply.'
            : '';

        $fix = $limit > 0 && $limit < 90
            ? ' Raise max_execution_time to 120 in php.ini or the control panel\'s PHP settings.'
            : ' The page itself may simply be slow to audit - try it at pagespeed.web.dev to compare.';

        return $message . '.' . $why . $fix
            . ($full ? ' Below ' . self::FULL_CATEGORY_BUDGET . 's only the performance score is requested.' : '');
    }

    private static function trim(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
