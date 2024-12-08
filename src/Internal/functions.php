<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Amp\File\FilesystemException;
use Amp\File\LockMode;

/**
 * @internal
 *
 * @param resource $handle
 *
 * @throws FilesystemException
 */
function lock(string $path, $handle, LockMode $mode): bool
{
    $error = null;
    \set_error_handler(static function (int $type, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    try {
        $flag = $mode === LockMode::Exclusive ? \LOCK_EX : \LOCK_SH;
        $lock = \flock($handle, $flag | \LOCK_NB, $wouldBlock);
    } finally {
        \restore_error_handler();
    }

    if ($lock) {
        return true;
    }

    if (!$wouldBlock) {
        throw new FilesystemException(\sprintf(
            'Error attempting to lock file at "%s": %s',
            $path,
            $error ?? 'Unknown error',
        ));
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
