<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\LockType;
use Amp\File\PendingOperationError;
use Amp\File\Whence;
use Amp\Future;
use function Amp\async;

/**
 * @internal
 * @implements \IteratorAggregate<int, string>
 */
abstract class QueuedWritesFile implements File, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    /** @var \SplQueue<Future<null>> */
    protected readonly \SplQueue $queue;

    protected int $position;

    protected bool $isReading = false;

    private bool $writable;

    protected ?LockType $lockType = null;

    public function __construct(
        private readonly string $path,
        private readonly string $mode,
        protected int $size,
    ) {
        if (!\strlen($mode)) {
            throw new \ValueError('File mode cannot be empty');
        }

        $this->queue = new \SplQueue();
        $this->writable = !\str_contains($this->mode, 'r') || \str_contains($this->mode, '+');
        $this->position = \str_contains($this->mode, 'a') ? $this->size : 0;
    }

    public function __destruct()
    {
        async($this->close(...));
    }

    abstract public function read(
        ?Cancellation $cancellation = null,
        int $length = self::DEFAULT_READ_LENGTH,
    ): ?string;

    /**
     * @return Future<null>
     */
    abstract protected function push(string $data, int $position): Future;

    public function write(string $bytes): void
    {
        if ($this->isReading) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        if ($this->queue->isEmpty()) {
            $future = $this->push($bytes, $this->position);
        } else {
            $position = $this->position;
            /** @var Future $future */
            $future = $this->queue->top()->map(fn () => $this->push($bytes, $position)->await());
        }

        $this->queue->push($future);

        $future->await();
    }

    public function end(): void
    {
        $this->writable = false;

        if ($this->queue->isEmpty()) {
            $this->close();
            return;
        }

        $future = $this->queue->top()->finally($this->close(...));
        $this->queue->push($future);

        $future->await();
    }

    /**
     * @return Future<null>
     */
    abstract protected function trim(int $size): Future;

    public function truncate(int $size): void
    {
        if ($this->isReading) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        if ($this->queue->isEmpty()) {
            $future = $this->trim($size);
        } else {
            $future = $this->queue->top()->map(fn () => $this->trim($size)->await());
        }

        $this->queue->push($future);

        $future->await();
    }

    /**
     * @return resource
     *
     * @throws ClosedException If the file has been closed.
     */
    abstract protected function getFileHandle();

    public function lock(LockType $type, ?Cancellation $cancellation = null): void
    {
        lock($this->path, $this->getFileHandle(), $type, $cancellation);
        $this->lockType = $type;
    }

    public function tryLock(LockType $type): bool
    {
        $locked = tryLock($this->path, $this->getFileHandle(), $type);
        if ($locked) {
            $this->lockType = $type;
        }

        return $locked;
    }

    public function unlock(): void
    {
        unlock($this->path, $this->getFileHandle());
        $this->lockType = null;
    }

    public function getLockType(): ?LockType
    {
        return $this->lockType;
    }

    public function seek(int $position, Whence $whence = Whence::Start): int
    {
        if ($this->isReading) {
            throw new PendingOperationError;
        }

        return match ($whence) {
            Whence::Start => $this->position = $position,
            Whence::Current => $this->position += $position,
            Whence::End => $this->position = $this->size + $position,
            default => throw new \Error("Invalid whence parameter; Start, Current or End expected"),
        };
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->queue->isEmpty() && $this->size <= $this->position;
    }

    public function isReadable(): bool
    {
        return !$this->isClosed();
    }

    public function isSeekable(): bool
    {
        return !$this->isClosed();
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
