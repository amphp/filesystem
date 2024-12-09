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
    static $latencyTimeout = 0.01;
    static $delayLimit = 1;

    $error = null;
    $errorHandler = static function (int $type, string $message) use (&$error): bool {
        $error = $message;
        return true;
    };

    $flags = \LOCK_NB | match ($type) {
        LockType::Shared => \LOCK_SH,
        LockType::Exclusive => \LOCK_EX,
    };

    for ($attempt = 0; true; ++$attempt) {
        \set_error_handler($errorHandler);
        try {
            $lock = \flock($handle, $flags, $wouldBlock);
        } finally {
            \restore_error_handler();
        }

        if ($lock) {
            return;
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

        delay(\min($delayLimit, $latencyTimeout * (2 ** $attempt)), cancellation: $cancellation);
    }
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
