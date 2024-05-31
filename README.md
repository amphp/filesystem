# amphp/file

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This package provides an abstraction layer and non-blocking file access solution that keeps your application responsive.

[![Latest Release](https://img.shields.io/github/release/amphp/file.svg?style=flat-square)](https://github.com/amphp/file/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/file/blob/3.x/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/file
```

## Requirements

- PHP 8.1+

`amphp/file` works out of the box without any PHP extensions.
It uses multiple processes by default, but also comes with a blocking driver that uses PHP's blocking functions in the current process.

Extensions allow using threading in the background instead of using multiple processes and will automatically be used if they're available:

- [`ext-eio`](https://pecl.php.net/package/eio)
- [`ext-uv`](https://github.com/amphp/ext-uv)
- [`ext-parallel`](https://github.com/krakjoe/parallel)

## Usage

### Reading Files

Read the specified file's contents:

```php
$contents = Amp\File\read('/path/to/file.txt');
```

### Writing Files

Write the contents string to the specified path:

```php
Amp\File\write('/path/to/file.txt', 'contents');
```

### File Handles

Instead of reading or writing an entire file, the library also allows opening a `File` handle, e.g. to stream data:

```php
$file = Amp\File\openFile('/path/to/file.txt', 'r');
Amp\ByteStream\pipe($file, getStdout());
```

 - `File::read()`: See also `File::isReadable`.
 - `File::write()`: See also `File::isWritable`.
 - `File::end()`: See `WritableStream::end()`.
 - `File::close()`: Close the file handle.
 - `File::isClosed()` can be used to check if the file handle has already been closed. 
 - `File::onClose()` can be used ot register a callback that's called on file handle closure.
 - `File::seek()`: Set the internal pointer position and returns the new offset position.
   - `SEEK_SET`: Set position equal to offset bytes.
   - `SEEK_CUR`: Set position to current location plus offset.
   - `SEEK_END`: Set position to end-of-file plus offset.
 - `File::isSeekable`: Not documented, yet.
 - `File::tell()`: Return the current internal offset position of the file handle.
 - `File::eof()`: Test for being at the end of the stream (a.k.a. "end-of-file").
 - `File::getPath()`: Retrieve the path used when opening the file handle.
 - `File::getMode()`: Retrieve the mode used when opening the file handle.
 - `File::truncate()`: Truncates the file to the given length. If `$size` is larger than the current file size, the file is extended with NUL bytes.

### Metadata

File metadata refers to the descriptive information about a file, distinct from its actual content. This includes details like the file's size, permissions, creation and modification timestamps, ownership, and file type. Metadata provides essential context about the file's attributes, enabling users and applications to understand its characteristics without delving into the content. Unlike the file's actual data, which holds the meaningful information or instructions, metadata focuses on administrative and structural attributes that aid in file organization, access, and management.

#### Existence

 - `exists()`: Checks whether the specified path exists.

#### File Size

 - `getSize()`: Returns the file size in bytes.

#### File Type

 - `isDirectory()`: Checks whether the given path exists and is a directory.
 - `isFile()`: Checks whether the given path exists and is a regular file.
 - `isSymlink()`: Checks whether the given path exists and is a symlink.

#### File Timestamps

 - `getModificationTime()`: Returns the file's modification time as Unix timestamp in seconds.
 - `getAccessTime()`: Returns the file's access time as Unix timestamp in seconds.
 - `getCreationTime()`: Returns the file's creation time as Unix timestamp in seconds.

#### Low Level Access

> **Note**
> We highly recommend to work with the higher-level APIs like `exists()` if possible instead of directly working with the `getStatus()` / `getLinkStatus()` API.

File metadata can be obtained using the `getStatus()` function, or `getLinkStatus()` in case of symlinks if you want to access the link metadata instead of the target file. Either function will return `null` if the path doesn't exist.

```php
<?php

use Amp\File;

require __DIR__ . '/../vendor/autoload.php';

var_dump(File\getStatus(__FILE__));
```

```plain
array(13) {
  ["dev"]=>
  int(16777232)
  ["ino"]=>
  int(15186622)
  ["mode"]=>
  int(33188)
  ["nlink"]=>
  int(1)
  ["uid"]=>
  int(501)
  ["gid"]=>
  int(20)
  ["rdev"]=>
  int(0)
  ["size"]=>
  int(104)
  ["blksize"]=>
  int(4096)
  ["blocks"]=>
  int(8)
  ["atime"]=>
  int(1692381227)
  ["mtime"]=>
  int(1692381226)
  ["ctime"]=>
  int(1692381226)
}
```

### File Organization

 - `move()`: Move / rename a file or directory.
 - `deleteFile()`: Deletes a file.
 - `createDirectory()`: Creates a directory.
 - `createDirectoriesRecursively()`: Creates a directory and its parents.
 - `deleteDirectory()`: Deletes a directory.
 - `listFiles()`: List all files and subdirectories in a directory.
 - `changePermissions()`: Change the permissions of a file or directory.
 - `changeOwner()`: Change the ownership of a file or directory.
 - `touch()`: Update the access and modification time of the specified path. If the file does not exist it will be created automatically.

### Links

 - `createHardlink()`: Creates a new hardlink.
 - `createSymlink()`: Creates a new symlink.
 - `resolveSymlink()`: Resolves a symlink to its target path.

## Versioning

`amphp/file` follows the [semver](https://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
