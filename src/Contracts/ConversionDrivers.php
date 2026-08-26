<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHICH DRIVER ANSWERS FOR A GIVEN TYPE.
 *
 * ⚠️ THIS IS A SECOND CONTRACT RATHER THAN A METHOD ON THE FIRST, and the reason is that
 * {@see ConversionDriver::convert()} never learns the source's type: it receives a path, and a
 * path is only a name. A driver that stood in front of the others would therefore have to guess
 * at conversion time what it had already been told at selection time — or remember it, which is
 * a piece of state between two calls and the first thing to go wrong under a queue.
 *
 * ⚠️ AND KEEPING `ConversionDriver` UNTOUCHED IS THE POINT. A host who has written their own
 * driver keeps it working; what changes is who is asked, not what a driver must implement.
 *
 * ⚠️ NULL IS A NORMAL ANSWER. A machine without ffmpeg has nothing to do with a video, and
 * saying so is what stops a "failed" row being written for a derivative that was never
 * attempted — a mark that sends somebody looking for a failure where there was no work.
 */
interface ConversionDrivers
{
    public function for(string $mimeType): ?ConversionDriver;
}
