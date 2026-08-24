<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Kryption\MediaHub\Contracts\UploadValidator;
use Kryption\MediaHub\Support\ImagickGuard;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Support\ExtensionFamilies;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * WHAT IS REFUSED, AND IN WHICH ORDER.
 *
 * ⚠️ THE ORDER IS NOT A PREFERENCE: each check assumes the previous one passed, and above all,
 * THE FILE IS NEVER WRITTEN BEFORE IT IS KNOWN. The module this package replaces stopped at the
 * second check — the declared extension — and wrote the file before knowing what it contained.
 * That is the most common weakness in this kind of module, and the most expensive one.
 *
 * ⚠️ THE TYPE DECLARED BY THE CLIENT IS NEVER BELIEVED. Neither the header nor the extension in
 * the name: those are strings we are handed. The real type is read from the first bytes.
 *
 * ⚠️ AND THE AGREEMENT BETWEEN THE TWO IS A CHECK IN ITS OWN RIGHT. A `.jpg` that is an SVG
 * passes an extension allow-list and a type check taken separately: it only gets through
 * because nobody confronts them with each other.
 */
final class DeepUploadValidator implements UploadValidator
{
    public function __construct(private readonly Config $config)
    {
    }

    public function validate(UploadedPayload $payload, array $context): void
    {
        if (! $payload->isInspectable()) {
            /*
             * ⚠️ WE REFUSE RATHER THAN ASSUME. A payload we cannot open cannot be validated;
             * letting it through "because it comes from a trusted stream" would reopen exactly
             * the hole being closed here.
             */
            throw UploadRejected::because('not_inspectable');
        }

        $path = (string) $payload->localPath;

        $this->refuseIfTooLarge($path, $payload);
        $this->refuseUnlistedExtension($payload);

        $realType = $this->realMimeType($path);

        $this->refuseSvg($realType);
        $this->refuseIncoherentExtension($payload, $realType);
        $this->refuseOversizedImage($path, $realType);
    }

    /** THE REAL TYPE — read from the first bytes, never from what we are told. */
    public function realMimeType(string $path): string
    {
        $info = @finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            throw UploadRejected::because('mime_unreadable');
        }

        $type = @finfo_file($info, $path);
        finfo_close($info);

        if (! is_string($type) || $type === '') {
            throw UploadRejected::because('mime_unreadable');
        }

        return strtolower($type);
    }

    // ── 1. Size ──────────────────────────────────────────────────────────────

    private function refuseIfTooLarge(string $path, UploadedPayload $payload): void
    {
        $ceiling = (int) $this->config->get('mediahub.uploads.max_size', 8192) * 1024;

        if ($ceiling <= 0) {
            return;
        }

        $size = $payload->size ?? (filesize($path) ?: 0);

        if ($size > $ceiling) {
            throw UploadRejected::because('too_large');
        }
    }

    // ── 2. The declared extension ────────────────────────────────────────────

    private function refuseUnlistedExtension(UploadedPayload $payload): void
    {
        $allowed = (array) $this->config->get('mediahub.uploads.allowed_extensions', []);

        if ($allowed === []) {
            return;
        }

        $extension = $payload->declaredExtension();

        /*
         * ⚠️ A FILE WITHOUT AN EXTENSION IS REFUSED. This is not rigidity: on the estate that
         * served as the field, two files are named `…272552.` — a trailing dot and nothing
         * behind it. They cannot be found, and never will be.
         */
        if ($extension === '' || ! in_array($extension, array_map('strtolower', $allowed), true)) {
            throw UploadRejected::because('extension_not_allowed');
        }
    }

    // ── 3. SVG ───────────────────────────────────────────────────────────────

    /**
     * ⚠️ AN SVG IS AN EXECUTABLE DOCUMENT, NOT AN IMAGE. It carries scripts, external
     * references and entities: served inline from our domain, it runs in the context of our
     * users. We refuse it until we have decided either to sanitise it or to serve it as an
     * attachment.
     */
    private function refuseSvg(string $realType): void
    {
        if (str_contains($realType, 'svg')) {
            throw UploadRejected::because('svg_not_allowed');
        }
    }

    // ── 4. Agreement ─────────────────────────────────────────────────────────


    /**
     * ⚠️ THE CHECK THAT CATCHES THE DISGUISE. Taken separately, the allow-list and the real
     * type let through a `.jpg` that is something else: it is confronting them that refuses.
     *
     * ⚠️ AND WE COMPARE FAMILIES, NOT STRINGS. A `.jpg` may be `image/jpeg`, an `.mp3`
     * `audio/mpeg`: demanding an exact match would refuse perfectly legitimate files, which
     * would push someone to turn the check off.
     *
     * ⚠️ AN EXTENSION ABSENT FROM THE TABLE IS NOT CHECKED HERE. Documents — PDF, office
     * formats, archives — have no stable MIME family, and the extension allow-list has already
     * filtered them.
     */
    private function refuseIncoherentExtension(UploadedPayload $payload, string $realType): void
    {
        $accepted = ExtensionFamilies::for($payload->declaredExtension());

        if ($accepted === null) {
            return;
        }

        /*
         * ⚠️ "NO OPINION" IS NOT A CONTRADICTION. The signature database is compiled into the
         * `fileinfo` extension and differs between PHP builds: measured, an M2TS stream is
         * recognised on PHP 8.4 and unknown on 8.2 and 8.3. Rejecting on "unknown" made the
         * very same upload succeed or fail depending on the runtime — and the extension
         * allow-list has already bounded what may be sent. What this check must catch is a
         * type that CONTRADICTS the extension, not one the host cannot name.
         *
         * ⚠️ IMAGES ARE EXCLUDED FROM THAT LENIENCY, AND THE FIRST ATTEMPT GOT IT WRONG.
         * Granting it to every family let an image with an unreadable header through: the
         * dimension ceiling that guards against decompression bombs only runs on a type
         * starting with `image/`, so an unidentifiable file carrying an image extension
         * skipped it entirely. For time-based containers there is no such downstream check to
         * lose; for images there is, and it is the one that matters.
         */
        if ($realType === ExtensionFamilies::NO_OPINION
            && ExtensionFamilies::primary($payload->declaredExtension()) !== 'image') {
            return;
        }

        /* ⚠️ `video/MP2T` comes back uppercase: `$realType` is lowercased upstream. */
        if (! in_array((string) strtok($realType, '/'), $accepted, true)) {
            throw UploadRejected::because('extension_mismatch');
        }
    }

    // ── 5. Dimensions ────────────────────────────────────────────────────────

    /**
     * THE IMAGE FORMATS `getimagesize()` CANNOT OPEN.
     *
     * ⚠️ THE LIST IS SHORT AND CLOSED ON PURPOSE. For everything else, an unreadable header
     * stays a refusal — that is the original behaviour, and widening it would weaken the guard
     * where it already works.
     *
     * @var array<int, string>
     */
    private const OUTSIDE_GETIMAGESIZE = [
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
    ];

    /**
     * ⚠️ THE GUARD AGAINST DECOMPRESSION BOMBS, AND IT COMES BEFORE DECODING. An image of a few
     * kilobytes can claim several gigabytes once expanded in memory: measuring after decoding
     * means measuring after being had. We read the header, which is enough to know the
     * dimensions.
     *
     * ⚠️ AND `getimagesize()` DOES NOT KNOW HEIC — the default format of every iPhone photo.
     * Refusing what it cannot read shut the door on an entire estate. Those formats are
     * measured with ImageMagick's `pingImage()`, which also reads the header only, and which is
     * bounded like the rest.
     */
    private function refuseOversizedImage(string $path, string $realType): void
    {
        if (! str_starts_with($realType, 'image/')) {
            return;
        }

        $ceiling = (int) $this->config->get('mediahub.uploads.max_image_pixels', 50_000_000);

        if ($ceiling <= 0) {
            return;
        }

        $dimensions = @getimagesize($path);

        if ($dimensions !== false) {
            if (($dimensions[0] * $dimensions[1]) > $ceiling) {
                throw UploadRejected::because('image_too_large');
            }

            return;
        }

        if (! in_array($realType, self::OUTSIDE_GETIMAGESIZE, true)) {
            /*
             * An unreadable header on a format `getimagesize()` does know: we do not guess.
             * Refusal is the only choice that assumes nothing.
             */
            throw UploadRejected::because('image_unreadable');
        }

        $pixels = ImagickGuard::pixels($path, ImagickGuard::limits($this->config));

        if ($pixels === null) {
            /*
             * ⚠️ NOBODY HERE CAN OPEN THIS FILE — therefore nobody will expand it in memory,
             * and the guard has nothing to guard against. Refusing it would deny uploads to
             * iPhone photos on every host without libheif, for a danger that only exists if
             * something decodes.
             *
             * ⚠️ AND IT IS THE SAME QUESTION BEING ASKED: if `pingImage()` fails on these
             * bytes, a full read would fail too. We do not assume the inability, we observe it
             * on the file itself.
             */
            return;
        }

        if ($pixels > $ceiling) {
            throw UploadRejected::because('image_too_large');
        }
    }
}
