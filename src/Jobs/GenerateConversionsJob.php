<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Models\Media;

/**
 * DERIVATIVES ARE BUILT OUTSIDE THE REQUEST.
 *
 * ⚠️ RESIZING IS COUNTED IN SECONDS, NOT MILLISECONDS. Doing it during the upload makes the
 * person uploading wait, and a multiple upload multiplies that wait until it times out — for an
 * accessory whose absence prevents nothing.
 *
 * ⚠️ AND ON A `sync` QUEUE IT STILL RUNS, IMMEDIATELY. A host without a queue worker is
 * therefore not a host without thumbnails: that is Laravel's default behaviour, and the package
 * has no business imposing another.
 */
final class GenerateConversionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, array<string, mixed>>|null  $definitions  What to build, or `null` to
     *                                                                let the configuration decide.
     */
    public function __construct(
        public readonly Media $media,
        public readonly ?array $definitions = null,
    ) {
        /*
         * ⚠️ THE QUEUE IS CHOSEN IN THE CONFIGURATION, and `null` means "the default one".
         * Thumbnails are bulky and slow: isolating them keeps them from delaying emails and
         * notifications.
         */
        $queue = config('mediahub.conversions.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * ⚠️ THE DEFINITIONS TRAVEL WITH THE JOB, they are not read again when it runs. A collection
     * decides what its media need at the moment the file is attached; looking the collection up
     * later would mean serialising the model that owns it and asking it again — on a queue
     * worker, minutes later, possibly after somebody changed it.
     */
    public function handle(GenerateConversions $generate): void
    {
        $generate($this->media, $this->definitions);
    }
}
