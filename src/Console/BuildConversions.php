<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Console;

use Illuminate\Console\Command;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Contracts\ConversionDrivers;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;

/**
 * BUILDING DERIVATIVES FOR WHAT IS ALREADY IN THE LIBRARY.
 *
 * ⚠️ THEY ARE MADE ONCE, AT UPLOAD, WHICH IS RIGHT AND LEAVES A GAP. A library that predates the
 * tool drawing its thumbnails has none for anything already in it — and the alternative was
 * uploading every file a second time, doubling the storage and changing every identifier.
 *
 * ⚠️ IT SKIPS WHAT ALREADY HAS ONE UNLESS TOLD OTHERWISE. Redoing thirty thousand files to reach
 * the four hundred that need it is hours of processor time and thirty thousand writes to object
 * storage, for an answer identical to what was already there.
 *
 * ⚠️ AND IT ASKS THE DRIVERS RATHER THAN GUESSING FROM THE TYPE. A machine without ffmpeg has
 * nothing to do with a video, and walking every video on it to fail on each would fill the table
 * with marks nobody can act on. What cannot be drawn is counted and named, once, at the end.
 */
final class BuildConversions extends Command
{
    /** ⚠️ HOW MANY ROWS ARE HELD AT ONCE. A library is not a page, and `all()` on one is a
     * memory limit waiting for the right customer. */
    private const CHUNK = 100;

    protected $signature = 'mediahub:conversions
        {--missing : Only files that have no derivative at all}
        {--type=* : Restrict to media types — image, video, document, audio}
        {--scope= : Restrict to one scope key. Every scope by default}
        {--queue : Hand each file to the queue instead of doing the work now}
        {--limit=0 : Stop after this many files. 0 means all of them}';

    protected $description = 'Build the derivatives of files that are already stored';

    public function handle(ConversionDrivers $drivers, GenerateConversions $generate): int
    {
        if (! HostSchema::hasTable('conversions')) {
            $this->components->error('This installation has no conversions table, so there is nowhere to record a derivative.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $types = array_filter(array_map('strval', (array) $this->option('type')));

        $done = 0;
        $skipped = 0;
        $impossible = [];

        /*
         * ⚠️ EVERY SCOPE, AND IT HAS TO BE SAID OUT LOUD. A media is invisible outside its own
         * scope, and a terminal has none: run without this, the command answered "0 file(s)
         * built" on an installation holding fifty-three of them — measured, on a real one, the
         * day this shipped. It looked like there was nothing to do.
         *
         * ⚠️ SO IT USES THE NAMED WAY OUT rather than writing the query by hand. The model offers
         * `withoutMediaScope()` for exactly this, and a maintenance command going around scoping
         * silently would be a poor precedent in a package whose whole discipline is that the
         * scope IS the boundary.
         */
        $query = Media::query()->withoutMediaScope()->orderBy(Media::column('id'));

        /*
         * ⚠️ AND THE TRASH IS PUT BACK, because the way out takes every global scope with it —
         * soft deletion included. Rebuilding the thumbnails of files somebody is in the middle of
         * throwing away is work nobody asked for, on the storage they are trying to free.
         */
        $query->whereNull(Media::column('deleted_at'));

        $narrowed = (string) ($this->option('scope') ?? '');

        if ($narrowed !== '') {
            $query->where('scope_key', $narrowed);
        }

        /*
         * ⚠️ THE TYPE IS NOT A WHERE CLAUSE, BECAUSE IT IS NOT ALWAYS A COLUMN. It is derived
         * from the MIME type by {@see \Kryption\MediaHub\Contracts\MediaTypeResolver}, and the
         * shipped `legacy` preset maps it to nothing at all: asked for one in SQL, the query
         * raises `column_absent_in_host_schema: type` and the command dies before doing anything.
         * Measured on a real installation, running exactly the command the documentation
         * suggests.
         *
         * ⚠️ SO IT IS FILTERED IN THE LOOP, which walks every row either way — `--missing`
         * already does — and works on every schema this package supports rather than on the one
         * it was written against.
         */

        $query->chunkById(self::CHUNK, function ($media) use (
            $drivers, $generate, $limit, $types, &$done, &$skipped, &$impossible
        ): bool {
            foreach ($media as $one) {
                if ($limit > 0 && $done >= $limit) {
                    return false;
                }

                if ($types !== [] && ! in_array($one->mediaType()->value, $types, true)) {
                    continue;
                }

                $type = (string) $one->mime_type;

                if ($drivers->for($type) === null) {
                    /* ⚠️ COUNTED BY TYPE RATHER THAN LISTED. Ten thousand audio files would
                     * otherwise scroll past and bury the line that mattered. */
                    $impossible[$type] = ($impossible[$type] ?? 0) + 1;

                    continue;
                }

                if ($this->option('missing') && $this->alreadyHasOne($one)) {
                    $skipped++;

                    continue;
                }

                if ($this->option('queue')) {
                    GenerateConversionsJob::dispatch($one);
                } else {
                    $generate($one);
                }

                $done++;
            }

            return true;
        }, Media::column('id'));

        $this->report($done, $skipped, $impossible, $narrowed);

        return self::SUCCESS;
    }

    /**
     * ⚠️ "HAS ONE" MEANS A READY ONE. A row left at `failed` or `pending` is exactly what somebody
     * running this command wants to retry — treating it as done would make `--missing` useless on
     * the files it was written for.
     */
    private function alreadyHasOne(Media $media): bool
    {
        return MediaConversion::query()
            ->where('media_id', $media->getKey())
            ->where('state', 'ready')
            ->exists();
    }

    /** @param  array<string, int>  $impossible */
    private function report(int $done, int $skipped, array $impossible, string $narrowed): void
    {
        /*
         * ⚠️ SAID BEFORE THE FIGURES, because it changes what they mean. Somebody running this on
         * a multi-tenant installation has a right to know it crossed every scope rather than
         * finding out from a customer.
         */
        $this->components->info($narrowed === ''
            ? 'Walking every scope.'
            : 'Walking the scope "'.$narrowed.'" only.');

        $this->components->info($this->option('queue')
            ? $done.' file(s) handed to the queue.'
            : $done.' file(s) built.');

        if ($skipped > 0) {
            $this->components->info($skipped.' already had one and were left alone.');
        }

        if ($impossible === []) {
            return;
        }

        /*
         * ⚠️ NAMED, NOT SWALLOWED. "Nothing can be drawn for these" is the answer to the question
         * somebody will ask next — why is that folder still all icons — and a silent skip sends
         * them to the code instead.
         */
        ksort($impossible);

        $this->components->warn('Nothing here can draw these, and they were passed over:');

        foreach ($impossible as $type => $count) {
            $this->components->twoColumnDetail($type, (string) $count);
        }
    }
}
