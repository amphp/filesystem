<?php declare(strict_types=1);

namespace Amp\File;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use Amp\Sync\SyncException;
use const Amp\Process\IS_WINDOWS;
use function Amp\delay;

final class FileMutex implements Mutex
{
    private const LATENCY_TIMEOUT = 0.01;
    private const DELAY_LIMIT = 1;

    /** @var array<string, DeferredFuture> */
    private static array $locks = [];

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

        // Await for another instance of the lock in the same process to be released.
        (self::$locks[$this->fileName] ?? null)?->getFuture()->await();

        self::$locks[$this->fileName] = $deferredFuture = new DeferredFuture();

        try {
            // Retry loop exists only for Windows.
            for ($attempt = 0; true; ++$attempt) {
                try {
                    $file = $this->filesystem->openFile($this->fileName, 'a');

                    $file->lock(LockMode::Exclusive, $cancellation);
                    return new Lock(fn () => $this->release($file, $deferredFuture));
                } catch (FilesystemException|StreamException $exception) {
                    if (!IS_WINDOWS) {
                        throw $exception;
                    }

                    // Windows fails to open the file if a lock is held.
                    delay(\min(self::DELAY_LIMIT, self::LATENCY_TIMEOUT * (2 ** $attempt)), cancellation: $cancellation);
                }
            }
        } catch (FilesystemException|StreamException $exception) {
            $deferredFuture->complete();
            unset(self::$locks[$this->fileName]);

            throw new SyncException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Releases the lock on the mutex.
     *
     * @throws SyncException
     */
    private function release(File $file, DeferredFuture $deferredFuture): void
    {
        try {
            $this->filesystem->deleteFile($this->fileName);
        } catch (FilesystemException $exception) {
            if (IS_WINDOWS) {
                return; // Windows will fail to delete the file if another handle is open.
            }

            throw new SyncException(
                'Failed to unlock the mutex file: ' . $this->fileName,
                previous: $exception,
            );
        }

        unset(self::$locks[$this->fileName]);

        $file->close();
        $deferredFuture->complete();
    }
}
