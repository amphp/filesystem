<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Amp\Cancellation;
use Amp\File\FilesystemException;
use Amp\File\LockMode;
use function Amp\delay;

/**
 * @internal
 *
 * @param resource $handle
 *
 * @throws FilesystemException
 */
function lock(string $path, $handle, LockMode $mode, ?Cancellation $cancellation): void
{
    static $latencyTimeout = 0.01;
    static $delayLimit = 1;

    $error = null;
    $errorHandler = static function (int $type, string $message) use (&$error): bool {
        $error = $message;
        return true;
    };

    $flags = \LOCK_NB | match ($mode) {
        LockMode::Shared => \LOCK_SH,
        LockMode::Exclusive => \LOCK_EX,
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

        $multiplier = 2 ** \min(7, $attempt);
        delay(\min($delayLimit, $latencyTimeout * $multiplier), cancellation: $cancellation);
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
