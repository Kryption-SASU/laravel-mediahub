<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Enums;

/** What to do with a file that is already there, byte for byte. */
enum DuplicateDecision: string
{
    /** We record one more row, pointing at the existing object. */
    case Reuse = 'reuse';

    /** We write a second object, under another name. */
    case Duplicate = 'duplicate';

    /** We refuse the upload. */
    case Reject = 'reject';
}
