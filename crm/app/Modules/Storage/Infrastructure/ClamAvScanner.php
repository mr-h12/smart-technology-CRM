<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;

/**
 * clamd over TCP, using INSTREAM.
 *
 * INSTREAM rather than SCAN: SCAN hands clamd a path, which requires the daemon
 * to see the same filesystem as PHP. It does not — §17 puts attachments on a
 * volume mounted into php and the workers, and a scanner container would need
 * that mount too. Streaming the bytes over the socket keeps the two services
 * independent, which is what lets clamd live anywhere the network reaches.
 *
 * **Unverified against a live daemon.** No clamd exists in this stack yet, so
 * what the suite proves is the failure path: an unreachable daemon throws and
 * never answers "clean". Detection itself is on the deployment-debt register.
 */
final readonly class ClamAvScanner implements VirusScannerInterface
{
    /** clamd's default StreamMaxLength is 25 MB; chunks well under it are safest. */
    private const CHUNK = 8192;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeoutSeconds,
    ) {}

    public function scan($contents): ScanStatus
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            throw new ScannerUnavailable(
                "clamd unreachable at {$this->host}:{$this->port} ({$errno} {$error})"
            );
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($contents)) {
                $chunk = fread($contents, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                // Each chunk is length-prefixed, four bytes big-endian.
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            // A zero-length chunk ends the stream.
            fwrite($socket, pack('N', 0));

            $reply = (string) stream_get_contents($socket);
        } finally {
            fclose($socket);
        }

        return self::interpret($reply);
    }

    private static function interpret(string $reply): ScanStatus
    {
        $reply = trim($reply, "\0\r\n ");

        if (str_ends_with($reply, 'FOUND')) {
            return ScanStatus::Infected;
        }

        if (str_ends_with($reply, 'OK')) {
            return ScanStatus::Clean;
        }

        // Anything else — ERROR, a truncated reply, a timeout that returned
        // nothing — is not an answer, and must not be recorded as one.
        throw new ScannerUnavailable("clamd gave no usable answer: '{$reply}'");
    }
}
