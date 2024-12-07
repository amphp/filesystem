<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cancellation;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use Amp\Sync\SyncException;

final class KeyedFileMutex implements KeyedMutex
{
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

    /**
     * @throws SyncException
     */
    public function acquire(string $key, ?Cancellation $cancellation = null): Lock
    {
        $mutex = new FileMutex($this->getFilename($key), $this->filesystem);

        return $mutex->acquire($cancellation);
    }

    private function getFilename(string $key): string
    {
        return $this->directory . '/' . \hash('sha256', $key) . '.lock';
    }
}
