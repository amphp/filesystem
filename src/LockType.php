<?php declare(strict_types=1);

namespace Amp\File;

enum LockType
{
    case Shared;
    case Exclusive;
}
