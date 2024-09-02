<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cancellation;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use Amp\Sync\SyncException;
use function Amp\delay;

/**
 * Async mutex based on flock.
 * 
 * A crash of the program will automatically release the lock, but the lockfiles will never be removed from the filesystem (even in case of successful release).  
 * 
 * For a mutex that removes the lockfiles but does not release the lock in case of a crash (requiring manual user action to clean up), see FileMutex.
 */
final class LockingFileMutex implements Mutex
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

    public function acquire(?Cancellation $cancellation = null): Lock
    {
        if (!$this->filesystem->isDirectory($this->directory)) {
            throw new SyncException(\sprintf('Directory of "%s" does not exist or is not a directory', $this->fileName));
        }
        $f = \fopen($this->fileName, 'c');

        // Try to create the lock file. If the file already exists, someone else
        // has the lock, so set an asynchronous timer and try again.
        for ($attempt = 0; true; ++$attempt) {
            if (\flock($f, LOCK_EX|LOCK_NB)) {
                $lock = new Lock(fn () => \flock($f, LOCK_UN));
                return $lock;
            }
            delay(\min(self::DELAY_LIMIT, self::LATENCY_TIMEOUT * (2 ** $attempt)), cancellation: $cancellation);
        }
    }
}
