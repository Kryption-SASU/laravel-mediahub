<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Http\Requests\Concerns\NormalisesKeys;
use Kryption\MediaHub\Models\Media;

/**
 * CHANGING A MEDIA — its displayed name, its free-form properties, its folder.
 *
 * ⚠️ HERE `authorize()` CAN ACTUALLY DO SOME WORK, because the media is already resolved by
 * route binding — therefore already through the scope. That is the difference from a selection
 * received in a request body, and it is why the two do not look alike.
 *
 * ⚠️ AND THERE IS NO `file_name` IN THESE RULES. The name on disk cannot be changed: allowing
 * it to be typed would make an object's location depend on a human keystroke, and would turn
 * every rename into a copy followed by a deletion.
 */
final class UpdateMediaRequest extends FormRequest
{
    use NormalisesKeys;

    /**
     * ⚠️ THE DESTINATION FOLDER IS A KEY IN ITS DECLARED FORM — the same reason as everywhere
     * else here: under the `legacy` preset it is the host's integer `id`.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseKeys(['folder']);
    }

    public function authorize(): bool
    {
        $media = $this->route('media');

        return $media instanceof Media && app(AccessPolicy::class)->modify($media);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'properties' => ['sometimes', 'array'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
