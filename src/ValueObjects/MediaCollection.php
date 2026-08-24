<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

/**
 * WHAT A HOST MODEL ACCEPTS UNDER ONE NAME — an avatar, a gallery, a set of attachments.
 *
 * ⚠️ A COLLECTION IS A RULE, NOT A FOLDER. It says what may be attached here and how many;
 * it says nothing about where the bytes are filed. Those are two different questions, and
 * conflating them is what makes moving a file a migration.
 *
 * ⚠️ AND AN UNDECLARED COLLECTION IS STILL A COLLECTION. A host that attaches to a name it
 * never registered gets one with no constraint rather than an error: the package should not
 * refuse work because a description is missing. What is declared is enforced; what is not is
 * simply unconstrained.
 */
final class MediaCollection
{
    private bool $single = false;

    /** @var array<int, string> */
    private array $accepts = [];

    private ?int $maxSizeInKilobytes = null;

    private ?string $disk = null;

    private ?string $fallbackUrl = null;

    /**
     * ⚠️ `null` MEANS "WHATEVER THE CONFIGURATION SAYS", AND IT IS NOT THE SAME AS AN EMPTY
     * ARRAY. A collection that says nothing has to keep behaving exactly as it did before this
     * existed, or adding the feature would silently change every installation that never asked
     * for it. An empty array is a collection that deliberately wants none.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $conversions = null;

    public function __construct(public readonly string $name)
    {
    }

    /**
     * ONE MEDIA AT A TIME — an avatar, a cover.
     *
     * ⚠️ ADDING A SECOND ONE REPLACES THE FIRST, it does not refuse it. Refusing would make
     * every "change the avatar" screen do the removal itself, and the one that forgets leaves a
     * model with two avatars and no way to say which is shown.
     */
    public function single(): self
    {
        $this->single = true;

        return $this;
    }

    /**
     * @param  string  ...$patterns  exact types (`application/pdf`) or families (`image/*`)
     */
    public function accepts(string ...$patterns): self
    {
        $this->accepts = array_map(strtolower(...), $patterns);

        return $this;
    }

    /** In kilobytes, like `uploads.max_size`. */
    public function maxSize(int $kilobytes): self
    {
        $this->maxSizeInKilobytes = $kilobytes;

        return $this;
    }

    /**
     * ⚠️ THE DISK IS A HOST DECISION, AND IT NEVER COMES FROM A REQUEST. Avatars on a public
     * bucket, contracts on a private one: the choice belongs to the code declaring the
     * collection. Nothing here is reachable from an HTTP payload.
     */
    /**
     * THE DERIVATIVES THIS COLLECTION WANTS.
     *
     * ⚠️ IT REPLACES THE CONFIGURED SET RATHER THAN ADDING TO IT. Merging would make the global
     * `thumb` impossible to remove: a collection that wants one large image would get two, and
     * nothing anywhere would let it say otherwise. Replacing means what is written here is what
     * is built, which is also what somebody reading the model expects.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     */
    public function conversions(array $definitions): self
    {
        $this->conversions = $definitions;

        return $this;
    }

    /**
     * ⚠️ NONE AT ALL — for attachments, invoices, anything nobody previews. Building thumbnails
     * for a folder of PDFs costs queue time and storage for images no screen ever shows.
     */
    public function withoutConversions(): self
    {
        $this->conversions = [];

        return $this;
    }

    public function onDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * WHAT TO SHOW WHEN THERE IS NOTHING.
     *
     * ⚠️ IT IS A URL, NOT A MEDIA. A fallback that were a real media would have to exist in the
     * library, be scoped, be deletable — and the day someone deleted it, every empty avatar in
     * the product would break at once. A string cannot be deleted by accident.
     */
    public function fallback(string $url): self
    {
        $this->fallbackUrl = $url;

        return $this;
    }

    public function isSingle(): bool
    {
        return $this->single;
    }

    /**
     * ⚠️ AN EMPTY LIST ACCEPTS EVERYTHING, and that is the useful default. Reading it as
     * "accepts nothing" would make an undeclared collection refuse every attachment, which is
     * the opposite of a package that starts without configuration.
     */
    public function acceptsType(string $mimeType): bool
    {
        if ($this->accepts === []) {
            return true;
        }

        $mimeType = strtolower(trim($mimeType));
        $family = (string) strtok($mimeType, '/');

        foreach ($this->accepts as $pattern) {
            if ($pattern === $mimeType) {
                return true;
            }

            if (str_ends_with($pattern, '/*') && $family.'/*' === $pattern) {
                return true;
            }
        }

        return false;
    }

    public function maxSizeInKilobytes(): ?int
    {
        return $this->maxSizeInKilobytes;
    }

    /**
     * What to build, or `null` to leave the decision to the configuration.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function conversionDefinitions(): ?array
    {
        return $this->conversions;
    }

    public function disk(): ?string
    {
        return $this->disk;
    }

    public function fallbackUrl(): ?string
    {
        return $this->fallbackUrl;
    }
}
