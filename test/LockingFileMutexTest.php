<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\File\LockingFileMutex;
use Amp\Sync\AbstractMutexTest;
use Amp\Sync\Mutex;

final class LockingFileMutexTest extends AbstractMutexTest
{
    public function createMutex(): Mutex
    {
        return new LockingFileMutex(\tempnam(\sys_get_temp_dir(), 'mutex-') . '.lock');
    }
}
