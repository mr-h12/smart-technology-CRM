<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\BackupNotWritten;
use App\Modules\Storage\Domain\Contracts\BackupWriterInterface;
use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Module 16 · 5 (`D-153`, `BK-01`) — one backup on disk: `pg_dump -Fc` of the
 * application's database (PostgreSQL's custom format, what `pg_restore` reads),
 * and the `secure_uploads` root as a gzipped tar built in PHP. In the Storage
 * module because filesystem access lives behind it (§14.2, §17;
 * `StorageServiceTest`'s guard), and Admin's `J-10` reaches it through
 * `StorageContract`. All or nothing:
 * whatever fails, the run's folder is removed and the reason thrown.
 *
 * `pg_dump` gets 80 seconds, under the job's 85 and the queue's `retry_after` of
 * 90, so a dump too slow for them is a recorded failure, not a killed job run
 * twice (debt row: a larger database needs a longer window).
 */
final class PgDumpBackupWriter implements BackupWriterInterface
{
    private const DUMP_TIMEOUT_SECONDS = 80;

    public function write(string $folder): array
    {
        $root = config('backups.path');
        if (! is_string($root) || $root === '') {
            throw new BackupNotWritten('The backups path (backups.path) is not set.');
        }

        $dir = rtrim($root, '/').'/'.$folder;

        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new BackupNotWritten("The backup folder {$dir} could not be created.");
            }

            return [
                'database' => $this->dump($dir, $folder),
                'files' => $this->archive($dir, $folder),
            ];
        } catch (Throwable $e) {
            self::remove($dir);

            throw $e instanceof BackupNotWritten ? $e : new BackupNotWritten($e->getMessage(), 0, $e);
        }
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    private function dump(string $dir, string $folder): array
    {
        /** @var array{host?: string, port?: string|int, database?: string, username?: string, password?: string} $db */
        $db = (array) config('database.connections.pgsql');
        $binary = config('backups.pg_dump');
        $target = $dir.'/database.dump';

        $process = new Process(
            [is_string($binary) ? $binary : 'pg_dump', '--format=custom', '--no-owner',
                '--host='.($db['host'] ?? ''), '--port='.($db['port'] ?? '5432'),
                '--username='.($db['username'] ?? ''), '--dbname='.($db['database'] ?? ''), '--file='.$target],
            null,
            ['PGPASSWORD' => (string) ($db['password'] ?? ''), 'PGCONNECT_TIMEOUT' => '10'],
            null,
            self::DUMP_TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new BackupNotWritten('pg_dump could not run: '.$e->getMessage(), 0, $e);
        }

        if (! $process->isSuccessful()) {
            $why = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'exit code '.$process->getExitCode();
            throw new BackupNotWritten('pg_dump failed: '.$why);
        }

        return self::artefact($target, $folder.'/database.dump');
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    private function archive(string $dir, string $folder): array
    {
        $source = config('filesystems.disks.secure_uploads.root');
        if (! is_string($source) || ! is_dir($source)) {
            throw new BackupNotWritten('The stored files folder (secure_uploads) cannot be read.');
        }

        $tar = $dir.'/files.tar';
        $archive = new PharData($tar);
        $archive->buildFromDirectory($source);
        // An empty files folder still makes a valid (empty) archive.
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tar);

        return self::artefact($dir.'/files.tar.gz', $folder.'/files.tar.gz');
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    private static function artefact(string $absolute, string $relative): array
    {
        $sha = hash_file('sha256', $absolute);
        $bytes = filesize($absolute);
        if ($sha === false || $bytes === false || $bytes === 0) {
            throw new BackupNotWritten("{$relative} was not written.");
        }

        return ['path' => $relative, 'sha256' => $sha, 'bytes' => $bytes];
    }

    private static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        /** @var \SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
