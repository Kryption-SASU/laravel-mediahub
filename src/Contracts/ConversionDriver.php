<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHAT BUILDS THE DERIVATIVES.
 *
 * ⚠️ `supports()` EXISTS SO THAT NO CAN BE AN ANSWER. Not every host has an image library, or a
 * video tool: a package that requires one does not install everywhere. An impossible derivative
 * never prevents the original from being served.
 */
interface ConversionDriver
{
    public function supports(string $mimeType): bool;

    /**
     * THE TYPE THAT WILL COME OUT FOR A GIVEN SOURCE — known BEFORE converting.
     *
     * ⚠️ THIS METHOD EXISTS BECAUSE THE DERIVATIVE'S PATH DEPENDS ON IT. Its extension must
     * describe its content: the thumbnail of a PDF is an image, calling it `.pdf` would have it
     * served with the wrong type by every host that deduces one from the other.
     *
     * ⚠️ AND THE SOURCE FORMAT IS KEPT WHEREVER POSSIBLE. Pushing everything to PNG would
     * inflate photographs, often by a factor of five.
     */
    public function outputMimeType(string $sourceMimeType): string;

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>  what is needed to find the produced derivative again
     */
    public function convert(string $disk, string $path, string $target, array $definition): array;
}
