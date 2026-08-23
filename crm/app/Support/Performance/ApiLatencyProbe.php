<?php

declare(strict_types=1);

namespace App\Support\Performance;

use InvalidArgumentException;
use RuntimeException;

/**
 * Issues real HTTP requests and reports how long each one took.
 *
 * Written against ext-curl rather than a benchmark binary because there is no
 * benchmark binary: `ab`, `wrk`, `hey` and `siege` were each checked with
 * `command -v` inside `crm-php:app` and none of them is present. `curl` and
 * `php` are.
 *
 * The duration comes from `CURLINFO_TOTAL_TIME_T`, which reports whole
 * microseconds. `CURLINFO_TOTAL_TIME_US` does not exist in this build — checked
 * against the container's curl 7.88.1 — and `CURLINFO_TOTAL_TIME` is a float in
 * seconds, which would put a float on the path to the verdict for no gain.
 */
final class ApiLatencyProbe
{
    /**
     * Long enough that a genuinely slow response is measured rather than cut
     * off and reported as a transport error, short enough that a hung server
     * ends the run instead of the run ending the day.
     */
    private const TIMEOUT_SECONDS = 30;

    /**
     * @param  positive-int  $requests  measured requests
     * @param  int<0, max>  $warmup  requests issued and discarded first
     *
     * @throws RuntimeException when curl cannot be initialised or a request
     *                          fails at the transport layer
     */
    public function measure(
        string $url,
        int $requests,
        int $warmup,
        bool $verifyTls,
    ): LatencyMeasurement {
        if ($url === '') {
            // CURLOPT_URL takes a non-empty string, and an empty one would
            // otherwise reach curl as a request to nowhere.
            throw new InvalidArgumentException('A URL is required to measure anything.');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('curl_init() returned false.');
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Deliberately off. Plain http:// on this stack answers 301 to
            // https://, and following it would measure two round trips while
            // reporting the 200 from the second — a wrong URL would then look
            // like a working one, only slower. Unfollowed, it surfaces as a 301
            // in `unexpectedStatuses` and fails the run.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ]);

        try {
            // One handle for the whole run, so the TCP connection and the TLS
            // session are established once and reused. That is what a SPA does
            // across a session, and it is the property that makes these numbers
            // comparable between runs; it also means the handshake cost is
            // charged to the warm-up and to nothing else.
            for ($i = 0; $i < $warmup; $i++) {
                $this->fire($handle);
            }

            $samples = [];
            $unexpected = [];

            for ($i = 0; $i < $requests; $i++) {
                [$status, $microseconds] = $this->fire($handle);

                if ($status === 200) {
                    $samples[] = $microseconds;

                    continue;
                }

                $unexpected[] = $status;
            }
        } finally {
            curl_close($handle);
        }

        return new LatencyMeasurement(
            LatencySamples::fromMicroseconds($samples),
            $unexpected,
            $requests,
        );
    }

    /**
     * @return array{int, int} the response code, and the total time in whole
     *                         microseconds
     *
     * @throws RuntimeException
     */
    private function fire(\CurlHandle $handle): array
    {
        if (curl_exec($handle) === false) {
            throw new RuntimeException('Request failed: '.curl_error($handle));
        }

        // Both are integers — verified at runtime against this image's curl
        // 7.88.1 before this class was written, and typed as such by the
        // extension's stubs, which is why no cast and no assertion appears
        // here. CURLINFO_TOTAL_TIME_T is whole microseconds.
        return [
            curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            curl_getinfo($handle, CURLINFO_TOTAL_TIME_T),
        ];
    }
}
