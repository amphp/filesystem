<?php declare(strict_types=1);

namespace Amp\File;

enum LockMode
{
    case Shared;
    case Exclusive;
}
