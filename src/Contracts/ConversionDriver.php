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
     * DOES DRAWING THIS NEED A PROGRAM OUTSIDE PHP.
     *
     * ⚠️ ONLY THE DRIVER KNOWS, AND ASKING ANYTHING ELSE GUESSES. "Video and PDF need one" is
     * true of the drivers shipped here and false the moment a host supplies its own; a caller
     * testing the mime type, or the class, would answer for the drivers it happens to know and
     * silently answer wrong for every other one.
     *
     * ⚠️ AND IT IS ASKED WHERE THE ANSWER CHANGES WHAT HAPPENS: a host that forbids `proc_open`
     * in a request can still build these on the queue, so the work moves rather than fails. A
     * driver that needs nothing but PHP is done on the spot as before.
     */
    public function needsAProgram(): bool;

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
