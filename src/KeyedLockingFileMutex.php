<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cancellation;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use Amp\Sync\SyncException;
use function Amp\delay;
/**
 * Async keyed mutex based on flock.
 * 
 * A crash of the program will automatically release the lock, but the lockfiles will never be removed from the filesystem (even in case of successful release).  
 * 
 * For a mutex that removes the lockfiles but does not release the lock in case of a crash (requiring manual user action to clean up), see KeyedFileMutex.
 */

final class KeyedLockingFileMutex implements KeyedMutex
{
    private const LATENCY_TIMEOUT = 0.01;
    private const DELAY_LIMIT = 1;

    private readonly Filesystem $filesystem;

    private readonly string $directory;

    /**
     * @param string $directory Directory in which to store key files.
     */
    public function __construct(string $directory, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? filesystem();
        $this->directory = \rtrim($directory, "/\\");
    }

    public function acquire(string $key, ?Cancellation $cancellation = null): Lock
    {
        if (!$this->filesystem->isDirectory($this->directory)) {
            throw new SyncException(\sprintf('Directory "%s" does not exist or is not a directory', $this->directory));
        }

        $filename = $this->getFilename($key);

        $f = \fopen($filename, 'c');

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

    private function getFilename(string $key): string
    {
        return $this->directory . '/' . \hash('sha256', $key) . '.lock';
    }
}
