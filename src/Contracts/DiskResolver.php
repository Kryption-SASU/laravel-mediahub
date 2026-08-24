<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHICH DISK FOR WHICH MEDIA.
 *
 * ⚠️ NO DISK NAME IS WRITTEN IN THE PACKAGE'S CODE. The original module hardcoded one in four
 * places: the package became untestable, and its state leaked from one environment to another.
 *
 * ⚠️ AND THE DISK IS RECORDED ON THE MEDIA: two media of the same product can live on two
 * disks, and switching storage does not rewrite the past.
 */
interface DiskResolver
{
    /** @param  array<string, mixed>  $context */
    public function forUpload(array $context): string;
}
