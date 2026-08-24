<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * WHAT IS REFUSED, AND IN WHICH ORDER.
 *
 * ⚠️ THE ORDER MATTERS: each check assumes the previous one passed. Size, extension, REAL TYPE
 * READ FROM THE CONTENT, agreement between extension and type, dimensions capped before
 * decoding, the fate of SVG, checksum.
 *
 * ⚠️ THE ORIGINAL MODULE STOPS AT THE SECOND and writes the file before knowing its type. That
 * is the most common weakness in this kind of module.
 */
interface UploadValidator
{
    /**
     * @param  array<string, mixed>  $context
     *
     * @throws \Kryption\MediaHub\Exceptions\UploadRejected
     */
    public function validate(UploadedPayload $payload, array $context): void;
}
