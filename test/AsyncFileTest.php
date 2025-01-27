<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\File;
use Amp\File\LockType;
use Amp\File\PendingOperationError;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

abstract class AsyncFileTest extends FileTest
{
    public function testSimultaneousReads()
    {
        $this->expectException(PendingOperationError::class);

        $handle = $this->driver->openFile(__FILE__, "r");

        $future1 = async(fn () => $handle->read(length: 20));
        $future2 = async(fn () => $handle->read(length: 20));

        $expected = \substr(File\read(__FILE__), 0, 20);
        $this->assertSame($expected, $future1->await());

        $future2->await();
    }

    public function testSeekWhileReading()
    {
        $this->expectException(PendingOperationError::class);

        $handle = $this->driver->openFile(__FILE__, "r");

        $future1 = async(fn () => $handle->read(length: 10));
        $future2 = async(fn () => $handle->read(length: 0));

        $expected = \substr(File\read(__FILE__), 0, 10);
        $this->assertSame($expected, $future1->await());

        $future2->await();
    }

    public function testReadWhileWriting()
    {
        $this->expectException(PendingOperationError::class);

        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $data = "test";

        $future1 = async(fn () => $handle->write($data));
        $future2 = async(fn () => $handle->read(length: 10));

        $this->assertNull($future1->await());

        $future2->await();
    }

    public function testWriteWhileReading()
    {
        $this->expectException(PendingOperationError::class);

        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $future1 = async(fn () => $handle->read(length: 10));
        $future2 = async(fn () => $handle->write("test"));

        $this->assertNull($future1->await());

        $future2->await();
    }

    public function testCancelReadThenReadAgain()
    {
        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $deferredCancellation = new DeferredCancellation();
        $deferredCancellation->cancel();

        $handle->write("test");
        $handle->seek(0);

        try {
            $handle->read(cancellation: $deferredCancellation->getCancellation(), length: 2);
            $handle->seek(0); // If the read succeeds (e.g.: ParallelFile), we need to seek back to 0.
        } catch (CancelledException) {
        }

        $this->assertSame("test", $handle->read());
    }

    public function testSimultaneousLock(): void
    {
        $this->setMinimumRuntime(0.1);
        $this->setTimeout(0.5);

        $path = Fixture::path() . "/lock";
        $handle1 = $this->driver->openFile($path, "c+");
        $handle2 = $this->driver->openFile($path, "c+");

        $future1 = async(fn () => $handle1->lock(LockType::Exclusive));
        $future2 = async(fn () => $handle2->lock(LockType::Exclusive));

        EventLoop::delay(0.1, function () use ($handle1, $handle2): void {
            // Either file could obtain the lock first, so check both and release the one which obtained the lock.
            if ($handle1->getLockType()) {
                self::assertNull($handle2->getLockType());
                self::assertSame(LockType::Exclusive, $handle1->getLockType());
                $handle1->unlock();
            } else {
                self::assertNull($handle1->getLockType());
                self::assertSame(LockType::Exclusive, $handle2->getLockType());
                $handle2->unlock();
            }
        });

        $future1->await();
        $future2->await();

        $handle1->close();
        $handle2->close();
    }

    public function testTryLockLoop(): void
    {
        $this->setMinimumRuntime(0.1);
        $this->setTimeout(0.3);

        $path = Fixture::path() . "/lock";
        $handle1 = $this->driver->openFile($path, "c+");
        $handle2 = $this->driver->openFile($path, "c+");

        self::assertTrue($handle1->tryLock(LockType::Exclusive));
        self::assertSame(LockType::Exclusive, $handle1->getLockType());

        EventLoop::delay(0.1, $handle1->unlock(...));

        $future = async(function () use ($handle2): void {
            while (!$handle2->tryLock(LockType::Exclusive)) {
                delay(0.1);
            }
        });

        $future->await();

        $handle1->close();
        $handle2->close();
    }
}
