<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kryption\MediaHub\Http\Requests\Concerns\NormalisesKeys;
use Kryption\MediaHub\ValueObjects\ItemSelection;

/**
 * A SELECTION RECEIVED FROM THE CLIENT — two typed lists, not one flat list with a flag.
 *
 * ⚠️ `authorize()` CANNOT DO THE WORK HERE, AND THAT NEEDS SAYING. At this point we only have
 * keys: nothing is resolved, so nothing is attached to a scope yet. The real authorisation
 * happens after resolution, item by item, in the controller — and that is also where the batch
 * is refused whole. Writing an `authorize()` here that returned `true` while suggesting a check
 * had taken place would be exactly what the original module did, where every `authorize()` was
 * a hardcoded `true`.
 *
 * ⚠️ WHAT IS CHECKED HERE IS SHAPE, NOT RIGHT: two arrays of strings, and not an empty batch.
 * An empty batch is not an authorisation error, it is a request that means nothing — and an
 * action that "succeeds" without doing anything hides the real problem, upstream.
 */
final class SelectionRequest extends FormRequest
{
    use NormalisesKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ THE KEYS ARE PUT IN THEIR DECLARED FORM BEFORE THEY ARE JUDGED. Under the `legacy`
     * preset the route key is the host's integer `id`, so a client returning what this very API
     * handed it sends numbers — and every operation carrying a selection answered 422.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseKeys(['media', 'folders']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'media' => ['array'],
            'media.*' => ['required', 'string', 'max:191'],
            'folders' => ['array'],
            'folders.*' => ['required', 'string', 'max:191'],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('media', []) === [] && $this->input('folders', []) === []) {
                $validator->errors()->add('media', 'selection_empty');
            }
        });
    }

    public function selection(): ItemSelection
    {
        return new ItemSelection(
            media: array_values((array) $this->input('media', [])),
            folders: array_values((array) $this->input('folders', [])),
        );
    }
}
