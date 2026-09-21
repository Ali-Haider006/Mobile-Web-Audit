<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;

/** Thin cURL wrapper with retry/backoff. */
final class Http
{
    public const USER_AGENT = 'MobileWebAudit/1.0 (+core web vitals tracker)';

    /**
     * @return array{status:int, body:string, url:string}
     */
    public static function get(string $url, int $timeout = 60, int $attempts = 3): array
    {
        $lastError = '';
        for ($try = 1; $try <= max(1, $attempts); $try++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: */*'],
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $status > 0 && $status < 500 && $status !== 429) {
                return ['status' => $status, 'body' => (string) $body, 'url' => $final];
            }

            $lastError = $err !== '' ? $err : 'HTTP ' . $status;
            if ($try < $attempts) {
                // 2s, 4s, 8s - PSI rate-limits with 429 and the odd 500.
                sleep(2 ** $try);
            }
        }

        throw new RuntimeException('Request to ' . $url . ' failed: ' . $lastError);
    }
}
