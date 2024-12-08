<?php declare(strict_types=1);

namespace Amp\File;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use Amp\Sync\SyncException;
use function Amp\delay;
use const Amp\Process\IS_WINDOWS;

final class FileMutex implements Mutex
{
    private const LATENCY_TIMEOUT = 0.01;
    private const DELAY_LIMIT = 1;

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
            try {
                $file = $this->filesystem->openFile($this->fileName, 'a');

                try {
                    if ($file->lock(LockMode::Exclusive)) {
                        return new Lock(fn () => $this->release($file));
                    }
                    $file->close();
                } catch (FilesystemException|StreamException $exception) {
                    throw new SyncException($exception->getMessage(), previous: $exception);
                }
            } catch (FilesystemException $exception) {
                if (!IS_WINDOWS) { // Windows fails to open the file if a lock is held.
                    throw new SyncException($exception->getMessage(), previous: $exception);
                }
            }

            $multiplier = 2 ** \min(31, $attempt);
            delay(\min(self::DELAY_LIMIT, self::LATENCY_TIMEOUT * $multiplier), cancellation: $cancellation);
        }
    }

    /**
     * Releases the lock on the mutex.
     *
     * @throws SyncException
     */
    private function release(File $file): void
    {
        try {
            $this->filesystem->deleteFile($this->fileName); // Delete file while holding the lock.
            $file->close();
        } catch (FilesystemException $exception) {
            throw new SyncException(
                'Failed to unlock the mutex file: ' . $this->fileName,
                previous: $exception,
            );
        }
    }
}
