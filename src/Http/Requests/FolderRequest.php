<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * CREATING, RENAMING OR MOVING A FOLDER.
 *
 * ⚠️ THE PARENT IS A KEY, NOT A PATH. Accepting a path would make the client the author of a
 * tree: it could name a folder that is not its own, or fabricate one on top. The key, on the
 * other hand, will be resolved by the model — therefore by the scope.
 *
 * ⚠️ AND `authorize()` APPLIES TO THE EXISTING FOLDER when there is one. On creation there is
 * nothing to authorise other than uploading: it is the same permission.
 */
final class FolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('folder');

        if ($folder instanceof MediaFolder) {
            return app(AccessPolicy::class)->modify($folder);
        }

        return app(AccessPolicy::class)->upload();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:191'],
            'parent' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
