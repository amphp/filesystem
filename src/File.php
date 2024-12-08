<?php declare(strict_types=1);

namespace Amp\File;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\Sync\Lock;

interface File extends ReadableStream, WritableStream
{
    public const DEFAULT_READ_LENGTH = 8192;

    /**
     * Read $length bytes from the open file handle.
     */
    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string;

    /**
     * Set the internal pointer position.
     *
     * @return int New offset position.
     */
    public function seek(int $position, Whence $whence = Whence::Start): int;

    /**
     * Return the current internal offset position of the file handle.
     */
    public function tell(): int;

    /**
     * Test for being at the end of the stream (a.k.a. "end-of-file").
     */
    public function eof(): bool;

    /**
     * @return bool Seeking may become unavailable if the underlying source is closed or lost.
     */
    public function isSeekable(): bool;

    /**
     * Retrieve the path used when opening the file handle.
     */
    public function getPath(): string;

    /**
     * Retrieve the mode used when opening the file handle.
     */
    public function getMode(): string;

    /**
     * Truncates the file to the given length. If $size is larger than the current file size, the file is extended
     * with null bytes.
     *
     * @param int $size New file size.
     */
    public function truncate(int $size): void;

    /**
     * Non-blocking method to obtain a shared or exclusive lock on the file.
     *
     * @throws FilesystemException If there is an error when attempting to lock the file.
     * @throws ClosedException If the file has been closed.
     */
    public function lock(LockMode $mode, ?Cancellation $cancellation = null): void;

    /**
     * @throws FilesystemException If there is an error when attempting to unlock the file.
     * @throws ClosedException If the file has been closed.
     */
    public function unlock(): void;

    /**
     * Returns the currently active lock mode, or null if the file is not locked.
     */
    public function getLockMode(): ?LockMode;
}
