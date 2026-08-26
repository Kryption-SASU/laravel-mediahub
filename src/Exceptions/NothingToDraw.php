<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;

/**
 * THERE WAS NO PICTURE IN THIS FILE — which is not the same as failing to make one.
 *
 * ⚠️ A "FAILED" ROW SIGNALS A FAULT, AND THERE IS NONE HERE. That distinction is older than this
 * class: recording a failure for something nobody could ever have drawn sends whoever reads it
 * looking for a broken tool, a bad file or a full disk, and there is nothing to find. The row is
 * removed instead, and the file keeps its type icon.
 *
 * ⚠️ THE CASE THAT MAKES IT NECESSARY IS THE `.wma`. It is an ASF container, so `finfo` answers
 * `video/x-ms-asf` for a file that is purely audio — a video type with no video in it. Asked for
 * a frame, ffmpeg finds no stream, writes nothing and exits without complaint.
 *
 * ⚠️ AND IT IS RAISED ONLY ON EVIDENCE. A driver that cannot tell "there was nothing" from "it
 * did not work" must report a failure: silence about a real fault is the other half of the same
 * mistake, and the more expensive one.
 */
final class NothingToDraw extends RuntimeException
{
}
