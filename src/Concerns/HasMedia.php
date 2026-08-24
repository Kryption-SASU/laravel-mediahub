<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Contracts\UploadValidator;
use Kryption\MediaHub\Contracts\UrlGenerator;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\DeepUploadValidator;
use Kryption\MediaHub\Support\MediaCollections;
use Kryption\MediaHub\ValueObjects\MediaCollection;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * ATTACHING MEDIA TO A HOST MODEL — the relation the library exists to make possible.
 *
 * ⚠️ ONE POLYMORPHIC RELATION INSTEAD OF A COLUMN PER CASE. Without it, every model that needs
 * a picture grows its own `*_media_id`, and a product ends up with half a dozen of them plus a
 * pivot table invented on the spot. They cannot be listed, cleaned up or reasoned about
 * together, and nothing tells you which files are still referenced.
 *
 * ⚠️ AND `addExistingMedia()` IS THE POINT. Attaching an UPLOADED file is what every library
 * does; attaching one that is ALREADY THERE is what a media library is for. A picker hands back
 * something the user chose from what they already have, and attaching it is one line — no
 * re-upload, no second copy of the bytes, no second row.
 *
 * ⚠️ THE COLLECTION IS A RULE, THE DISK IS A PLACE, AND THEY ARE NOT THE SAME QUESTION.
 * Declaring `onDisk()` sends new uploads somewhere; it does not move what is already attached,
 * and it does not decide the folder shown to a user.
 *
 * Usage:
 *
 *     class Post extends Model
 *     {
 *         use HasMedia;
 *
 *         public function registerMediaCollections(MediaCollections $collections): void
 *         {
 *             $collections->add('cover')->single()->accepts('image/*')->maxSize(4096);
 *             $collections->add('attachments');
 *         }
 *     }
 */
trait HasMedia
{
    /** The collection used when a caller names none. */
    public const DEFAULT_MEDIA_COLLECTION = 'default';

    private ?MediaCollections $registeredMediaCollections = null;

    /**
     * WHAT THIS MODEL ACCEPTS — overridden by the host, empty by default.
     *
     * ⚠️ THE REGISTRAR ARRIVES AS AN ARGUMENT. See `MediaCollections` for why that is not a
     * detail of style.
     */
    public function registerMediaCollections(MediaCollections $collections): void
    {
        //
    }

    /**
     * ⚠️ BUILT ONCE PER INSTANCE. Every attachment consults it, and rebuilding a description
     * that cannot change during a request would cost for nothing.
     */
    public function mediaCollections(): MediaCollections
    {
        if ($this->registeredMediaCollections === null) {
            $this->registeredMediaCollections = new MediaCollections();
            $this->registerMediaCollections($this->registeredMediaCollections);
        }

        return $this->registeredMediaCollections;
    }

    /** A declared collection, or an unconstrained one carrying the same name. */
    public function mediaCollection(string $name = self::DEFAULT_MEDIA_COLLECTION): MediaCollection
    {
        return $this->mediaCollections()->get($name) ?? new MediaCollection($name);
    }

    /**
     * ⚠️ THE TABLE MAY NOT EXIST, AND THE REFUSAL BELONGS HERE. An adopted schema has no linking
     * table: `HostSchema::table()` raises, and it raises the first time anything touches the
     * relation rather than on a query against a table that is not there. A host in `table` mode
     * that wants attachments declares the table, or does not use this trait.
     */
    public function media(): MorphToMany
    {
        return $this->morphToMany(
            Media::class,
            'mediable',
            HostSchema::table('mediables'),
            'mediable_id',
            'media_id'
        )->withPivot(['collection', 'position']);
    }

    /**
     * @return EloquentCollection<int, Media>
     */
    public function getMedia(string $collection = self::DEFAULT_MEDIA_COLLECTION): EloquentCollection
    {
        return $this->media()
            ->wherePivot('collection', $collection)
            ->orderByPivot('position')
            ->get();
    }

    public function getFirstMedia(string $collection = self::DEFAULT_MEDIA_COLLECTION): ?Media
    {
        return $this->media()
            ->wherePivot('collection', $collection)
            ->orderByPivot('position')
            ->first();
    }

    /**
     * ⚠️ `null` ONLY WHEN THERE IS NOTHING AND NO FALLBACK. A screen has to render something;
     * making it ask twice — "is there one?", then "what is its URL?" — is how empty avatars end
     * up as broken images.
     */
    public function getFirstMediaUrl(string $collection = self::DEFAULT_MEDIA_COLLECTION): ?string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return $this->mediaCollection($collection)->fallbackUrl();
        }

        return app(UrlGenerator::class)->url($media);
    }

    public function hasMedia(string $collection = self::DEFAULT_MEDIA_COLLECTION): bool
    {
        return $this->media()->wherePivot('collection', $collection)->exists();
    }

    /**
     * UPLOAD, THEN ATTACH.
     *
     * ⚠️ THE COLLECTION IS CHECKED BEFORE THE BYTES ARE WRITTEN, and on the REAL type. Checking
     * afterwards would mean deleting what was just stored — the exact ordering mistake the
     * upload action exists to avoid. Checking the declared type would mean a `.jpg` that is a
     * PDF satisfies `accepts('image/*')`.
     */
    public function addMedia(UploadedPayload $payload, string $collection = self::DEFAULT_MEDIA_COLLECTION): Media
    {
        $rules = $this->mediaCollection($collection);

        $this->refuseWhatTheCollectionRejects($rules, $payload);

        $context = [];

        if ($rules->disk() !== null) {
            $context['disk'] = $rules->disk();
        }

        if ($rules->conversionDefinitions() !== null) {
            $context['conversions'] = $rules->conversionDefinitions();
        }

        return $this->addExistingMedia(app(UploadMedia::class)($payload, $context), $collection);
    }

    /**
     * ATTACH SOMETHING ALREADY IN THE LIBRARY.
     *
     * ⚠️ IT TAKES A MODEL, NOT A KEY. A key received from a client would have to be resolved
     * here, and resolving it here is how an identifier belonging to somebody else gets attached
     * to your own record. The caller resolves it — through the scope, like everywhere else.
     *
     * ⚠️ AND ATTACHING TWICE DOES NOTHING. The pivot's primary key would refuse the second row
     * anyway; refusing loudly would make every "add if absent" screen check first, and the one
     * that forgets breaks on a double click.
     */
    public function addExistingMedia(Media $media, string $collection = self::DEFAULT_MEDIA_COLLECTION): Media
    {
        $rules = $this->mediaCollection($collection);

        if ($rules->isSingle()) {
            $this->clearMediaCollection($collection);
        }

        if ($this->media()->wherePivot('collection', $collection)->whereKey($media->getKey())->exists()) {
            return $media;
        }

        $this->media()->attach($media->getKey(), [
            'collection' => $collection,
            'position' => $this->nextMediaPosition($collection),
        ]);

        /*
         * ⚠️ A COLLECTION WITH ITS OWN DERIVATIVES GETS THEM HERE TOO, and not only on upload.
         * Without this, a cover chosen from the library — which is the whole point of having a
         * library — would be the one case that never receives the large version the collection
         * asked for, and the screen would fall back to a thumbnail with nothing explaining why.
         *
         * ⚠️ AND IT IS ADDITIVE, WHICH IS WHAT MAKES IT SAFE ON A SHARED FILE. Derivatives are
         * extra files keyed by name: building one more takes nothing away from the other models
         * pointing at the same media, and the original is never touched.
         */
        $wanted = $rules->conversionDefinitions();

        if ($wanted !== null && $wanted !== []) {
            GenerateConversionsJob::dispatch($media, $wanted);
        }

        return $media;
    }

    /**
     * THE COLLECTION BECOMES EXACTLY THIS, IN THIS ORDER.
     *
     * ⚠️ THE ORDER GIVEN IS THE ORDER KEPT. A gallery is arranged by a human dragging
     * thumbnails; storing the set without the sequence throws that work away, and the screen
     * shows a different order on the next load with nothing to explain it.
     *
     * @param  iterable<int, Media>  $media
     */
    public function syncMedia(iterable $media, string $collection = self::DEFAULT_MEDIA_COLLECTION): void
    {
        $pivot = [];
        $position = 0;

        foreach ($media as $item) {
            $pivot[$item->getKey()] = ['collection' => $collection, 'position' => $position++];
        }

        /*
         * ⚠️ DETACHING IS BOUNDED TO THIS COLLECTION. A plain `sync()` would drop what is
         * attached under every other name — a cover replaced would take the attachments with it.
         */
        $this->media()->wherePivot('collection', $collection)->detach();

        if ($pivot !== []) {
            $this->media()->attach($pivot);
        }
    }

    /** ⚠️ THE LINK GOES, THE MEDIA STAYS. Detaching is not deleting: the file belongs to the library. */
    public function removeMedia(Media $media, string $collection = self::DEFAULT_MEDIA_COLLECTION): void
    {
        $this->media()->wherePivot('collection', $collection)->detach($media->getKey());
    }

    public function clearMediaCollection(string $collection = self::DEFAULT_MEDIA_COLLECTION): void
    {
        $this->media()->wherePivot('collection', $collection)->detach();
    }

    /**
     * ⚠️ READ FROM THE PIVOT, NOT COUNTED. Counting rows gives the same number twice as soon as
     * something has been detached in between, and two media then share a position — after which
     * the order shown depends on whatever the engine feels like.
     */
    private function nextMediaPosition(string $collection): int
    {
        $highest = $this->media()
            ->wherePivot('collection', $collection)
            ->max(HostSchema::table('mediables').'.position');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    /**
     * ⚠️ THE REAL TYPE, READ FROM THE CONTENT. `accepts('image/*')` is a security rule as much
     * as a tidiness one: satisfied by a declared extension, it accepts an executable document
     * renamed `.png`, which is precisely what the upload validator refuses two steps later.
     */
    private function refuseWhatTheCollectionRejects(MediaCollection $rules, UploadedPayload $payload): void
    {
        if (! $payload->isInspectable()) {
            return;
        }

        $path = (string) $payload->localPath;

        $ceiling = $rules->maxSizeInKilobytes();

        if ($ceiling !== null) {
            $size = $payload->size ?? (filesize($path) ?: 0);

            if ($size > $ceiling * 1024) {
                throw OperationRejected::because('collection_file_too_large');
            }
        }

        $validator = app(UploadValidator::class);

        if (! $validator instanceof DeepUploadValidator) {
            return;
        }

        if (! $rules->acceptsType($validator->realMimeType($path))) {
            throw OperationRejected::because('collection_type_rejected');
        }
    }
}
