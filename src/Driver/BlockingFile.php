<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File\File;
use Amp\File\Internal;
use Amp\File\LockMode;
use Amp\File\Whence;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class BlockingFile implements File, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    /** @var resource|null */
    private $handle;

    private int $id;

    private readonly DeferredFuture $onClose;

    /**
     * @param resource $handle An open filesystem descriptor.
     * @param string $path File path.
     * @param string $mode File open mode.
     */
    public function __construct(
        $handle,
        private readonly string $path,
        private readonly string $mode,
    ) {
        $this->handle = $handle;
        $this->id = (int) $handle;

        if ($mode[0] === 'a') {
            \fseek($this->handle, 0, \SEEK_END);
        }

        $this->onClose = new DeferredFuture;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            \fclose($this->handle);
        }

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function lock(LockMode $mode): bool
    {
        return Internal\lock($this->path, $this->getFileHandle(), $mode);
    }

    public function unlock(): void
    {
        Internal\unlock($this->path, $this->getFileHandle());
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        $handle = $this->getFileHandle();

        try {
            \set_error_handler(function (int $type, string $message): never {
                throw new StreamException("Failed reading from file '{$this->path}': {$message}");
            });

            $data = \fread($handle, $length);
            if ($data === false) {
                throw new StreamException("Failed reading from file '{$this->path}'");
            }

            return $data !== '' ? $data : null;
        } finally {
            \restore_error_handler();
        }
    }

    public function write(string $bytes): void
    {
        $handle = $this->getFileHandle();

        try {
            \set_error_handler(function (int $type, string $message): never {
                throw new StreamException("Failed writing to file '{$this->path}': {$message}");
            });

            $length = \fwrite($handle, $bytes);
            if ($length === false) {
                throw new StreamException("Failed writing to file '{$this->path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function end(): void
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // ignore any errors
        }
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }

        try {
            \set_error_handler(function (int $type, string $message): never {
                throw new StreamException("Failed closing file '{$this->path}': {$message}");
            });

            if (\fclose($handle)) {
                return;
            }

            throw new StreamException("Failed closing file '{$this->path}'");
        } finally {
            \restore_error_handler();
        }
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function truncate(int $size): void
    {
        $handle = $this->getFileHandle();

        try {
            \set_error_handler(function (int $type, string $message): never {
                throw new StreamException("Could not truncate file '{$this->path}': {$message}");
            });

            if (!\ftruncate($handle, $size)) {
                throw new StreamException("Could not truncate file '{$this->path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function seek(int $position, Whence $whence = Whence::Start): int
    {
        $handle = $this->getFileHandle();

        $mode = match ($whence) {
            Whence::Start => SEEK_SET,
            Whence::Current => SEEK_CUR,
            Whence::End => SEEK_END,
            default => throw new \Error("Invalid whence parameter; Start, Current or End expected")
        };

        try {
            \set_error_handler(function (int $type, string $message): never {
                throw new StreamException("Could not seek in file '{$this->path}': {$message}");
            });

            if (\fseek($handle, $position, $mode) === -1) {
                throw new StreamException("Could not seek in file '{$this->path}'");
            }

            return $this->tell();
        } finally {
            \restore_error_handler();
        }
    }

    public function tell(): int
    {
        return \ftell($this->getFileHandle());
    }

    public function eof(): bool
    {
        return \feof($this->getFileHandle());
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function isWritable(): bool
    {
        return $this->handle !== null && $this->mode[0] !== 'r';
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return resource
     *
     * @throws ClosedException
     */
    private function getFileHandle()
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        return $this->handle;
    }
}
