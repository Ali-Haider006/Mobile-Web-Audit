<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;

/** Thin cURL wrapper with retry/backoff. */
final class Http
{
    public const USER_AGENT = 'MobileWebAudit/1.0 (+core web vitals tracker)';

    /**
     * One request. GET keeps its own retry/backoff below; anything that writes
     * is sent exactly once, because retrying a POST can duplicate whatever it
     * created.
     *
     * @param array<int,string> $headers
     * @return array{status:int, body:string, url:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 30
    ): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch);
        /*
         * No curl_close(): it has done nothing since PHP 8.0, where the handle
         * became an object freed by refcount, and PHP 8.5 deprecates it. The
         * notice printed before any redirect header, so on 8.5 the deprecation
         * itself broke the page.
         */
        unset($ch);

        if ($response === false) {
            throw new RuntimeException($method . ' ' . $url . ' failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return ['status' => $status, 'body' => (string) $response, 'url' => $url];
    }

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
            unset($ch);                 // see the note above: never curl_close()

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
