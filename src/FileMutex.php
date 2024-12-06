<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cancellation;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use Amp\Sync\SyncException;
use function Amp\delay;

final class FileMutex implements Mutex
{
    private const LATENCY_TIMEOUT = 0.01;
    private const DELAY_LIMIT = 1;

    private static ?\Closure $errorHandler = null;

    private readonly Filesystem $filesystem;

    private readonly string $directory;

    /**
     * @param string $fileName Name of temporary file to use as a mutex.
     */
    public function __construct(private readonly string $fileName, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? filesystem();
        $this->directory = \dirname($this->fileName);
    }

    /**
     * @throws SyncException
     */
    public function acquire(?Cancellation $cancellation = null): Lock
    {
        if (!$this->filesystem->isDirectory($this->directory)) {
            throw new SyncException(\sprintf('Directory of "%s" does not exist or is not a directory', $this->fileName));
        }

        // Try to create and lock the file. If flock fails, someone else already has the lock,
        // so set an asynchronous timer and try again.
        for ($attempt = 0; true; ++$attempt) {
            \set_error_handler(self::$errorHandler ??= static fn () => true);

            try {
                $handle = \fopen($this->fileName, 'c');
                if ($handle && \flock($handle, \LOCK_EX | \LOCK_NB)) {
                    return new Lock(fn () => $this->release($handle));
                }
            } finally {
                \restore_error_handler();
            }

            $multiplier = 2 ** \min(31, $attempt);
            delay(\min(self::DELAY_LIMIT, self::LATENCY_TIMEOUT * $multiplier), cancellation: $cancellation);
        }
    }

    /**
     * Releases the lock on the mutex.
     *
     * @param resource $handle
     *
     * @throws SyncException
     */
    private function release($handle): void
    {
        try {
            $this->filesystem->deleteFile($this->fileName);

            \set_error_handler(self::$errorHandler ??= static fn () => true);

            try {
                \fclose($handle);
            } finally {
                \restore_error_handler();
            }
        } catch (\Throwable $exception) {
            throw new SyncException(
                'Failed to unlock the mutex file: ' . $this->fileName,
                previous: $exception,
            );
        }
    }
}
