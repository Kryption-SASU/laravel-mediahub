<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

/**
 * WHAT THIS GD CAN ACTUALLY DO — asked once, and answerable without a GD.
 *
 * ⚠️ THIS CLASS EXISTS TO MAKE A PROPERTY PROVABLE, and that is its whole reason to be. GD is
 * compiled à la carte: a build without libjpeg cannot read JPEG even though the extension
 * reports as loaded. The driver must therefore ask the runtime rather than consult a list
 * written in the code — and a list written in the code, matching the machine it runs on, would
 * satisfy every test performed on that machine. Catching it used to require a GD built without a
 * format the list claimed; here it requires only `GdCapabilities::of([...])`.
 *
 * ⚠️ IT IS DELIBERATELY NOT A CONTRACT. A host has nothing to gain from replacing it: what it
 * describes is a fact about the running machine, not a decision. Putting it in `Contracts/` would
 * widen the package's public surface for nobody.
 *
 * ⚠️ AND THERE IS NO "IS GD LOADED" HERE. An absent GD and a GD that declares nothing answer no
 * to exactly the same questions; carrying a flag to tell them apart would be a line no test
 * could catch out. `absent()` names the intent, and that is all it needs to do.
 */
final class GdCapabilities
{
    /**
     * @param  array<string, bool>  $flags  the boolean entries of `gd_info()`
     */
    private function __construct(private readonly array $flags)
    {
    }

    /**
     * WHAT THE RUNNING MACHINE REPORTS.
     *
     * ⚠️ `gd_info()` MIXES BOOLEANS AND STRINGS — `GD Version` is a version number. Everything
     * that is not exactly `true` is read as a no, so a string can never be mistaken for a
     * capability.
     */
    public static function fromRuntime(): self
    {
        return extension_loaded('gd') ? self::of(gd_info()) : self::absent();
    }

    /**
     * A GD DESCRIBED BY HAND — the door this class was written for.
     *
     * ⚠️ IT IS NOT ONLY FOR TESTS. A host whose GD reports capabilities its delegates cannot
     * honour can describe the truth here and bind its own driver, rather than living with
     * thumbnails that are promised and never produced.
     *
     * @param  array<string, mixed>  $flags  keys as `gd_info()` spells them, e.g. `JPEG Support`
     */
    public static function of(array $flags): self
    {
        return new self(array_map(static fn (mixed $value): bool => $value === true, $flags));
    }

    /** A machine that can do nothing — no GD, or one that declares no capability. */
    public static function absent(): self
    {
        return new self([]);
    }

    /**
     * ⚠️ AN UNKNOWN FLAG IS A NO. `gd_info()` does not report what a build cannot do: it simply
     * omits it. Reading an absent key as anything but a refusal would promise every format the
     * caller's table happens to name.
     */
    public function has(string $flag): bool
    {
        return ($this->flags[$flag] ?? false) === true;
    }
}
