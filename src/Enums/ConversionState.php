<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Enums;

/**
 * THE STATE OF A DERIVATIVE.
 *
 * ⚠️ IT IS RECORDED, and that is what lets a screen show a placeholder rather than a broken
 * image while the thumbnail is being built.
 */
enum ConversionState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
