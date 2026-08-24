<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kryption\MediaHub\Contracts\AccessPolicy;

/**
 * UPLOADING FILES.
 *
 * ⚠️ THESE RULES DO NOT VALIDATE THE CONTENT, AND THAT IS DELIBERATE. No `mimes:`, no `max:` —
 * those rules act on the DECLARED extension and the declared size, two strings supplied by the
 * client. The real validation reads the type from the CONTENT, compares the dimensions before
 * decoding and computes a checksum: it lives in `UploadValidator`, after the file has arrived,
 * and it is the only one that counts. Doubling up here with a superficial validation would
 * suggest there are two.
 *
 * ⚠️ WHAT IS CHECKED HERE: that there are files at all, and that they arrived whole. An upload
 * truncated by a front-end server limit arrives as an invalid file, and must be reported
 * differently from a type refusal.
 */
final class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AccessPolicy::class)->upload();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
