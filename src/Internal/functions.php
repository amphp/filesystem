<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Amp\Cancellation;
use Amp\File\FilesystemException;
use Amp\File\LockType;
use function Amp\delay;

/**
 * @internal
 *
 * @param resource $handle
 *
 * @throws FilesystemException
 */
function lock(string $path, $handle, LockType $type, ?Cancellation $cancellation): void
{
    for ($attempt = 0; true; ++$attempt) {
        if (tryLock($path, $handle, $type)) {
            return;
        }

        // Exponential back-off with a maximum delay of 1 second.
        delay(\min(1, 0.01 * (2 ** $attempt)), cancellation: $cancellation);
    }
}

/**
 * @internal
 *
 * @param resource $handle
 *
 * @throws FilesystemException
 */
function tryLock(string $path, $handle, LockType $type): bool
{
    $flags = \LOCK_NB | match ($type) {
        LockType::Shared => \LOCK_SH,
        LockType::Exclusive => \LOCK_EX,
    };

    $error = null;
    \set_error_handler(static function (int $type, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    try {
        $lock = \flock($handle, $flags, $wouldBlock);
    } finally {
        \restore_error_handler();
    }

    if ($lock) {
        return true;
    }

    if (!$wouldBlock) {
        throw new FilesystemException(
            \sprintf(
                'Error attempting to lock file at "%s": %s',
                $path,
                $error ?? 'Unknown error',
            )
        );
    }

    return false;
}

/**
 * @internal
 *
 * @param resource $handle
 *
 * @throws FilesystemException
 */
function unlock(string $path, $handle): bool
{
    \set_error_handler(static function (int $type, string $message) use ($path): never {
        throw new FilesystemException(\sprintf('Error attempting to unlock file at "%s": %s', $path, $message));
    });

    try {
        \flock($handle, \LOCK_UN);
    } finally {
        \restore_error_handler();
    }

    return true;
}
