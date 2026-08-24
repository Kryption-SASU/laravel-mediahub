<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Kryption\MediaHub\Events\FolderCreated;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;

/**
 * CREATING A FOLDER.
 *
 * ⚠️ THE PARENT IS A MODEL, NOT AN IDENTIFIER RECEIVED FROM THE CLIENT. That is the rule for
 * every action in this package: they receive objects that are already resolved, therefore
 * already through the global scope. An action accepting a raw key would reopen the scoping leak
 * this package exists to close, for every new caller — and the next caller cannot be seen from
 * here.
 *
 * ⚠️ AND THE FOLDER EXISTS ONLY IN THE DATABASE. No directory is created on the storage: filing
 * the bytes belongs to the `PathGenerator`, and it has no reason to follow the tree the user
 * sees. Conflating the two would turn a simple rename into a file migration.
 */
final class CreateFolder
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  what the caller knows: owner, visibility
     */
    public function __invoke(string $name, ?MediaFolder $parent = null, array $context = []): MediaFolder
    {
        $name = trim($name);

        if ($name === '') {
            throw OperationRejected::because('folder_name_required');
        }

        /*
         * ⚠️ THE DEPTH IS BOUNDED ON WRITE. Without a bound, a client loop creating a folder
         * inside the previous one builds a tree no climb can walk any more — and the climb is
         * itself bounded: it would return truncated paths without saying so.
         */
        if ($parent !== null && ((int) $parent->depth) + 1 >= FolderTree::MAX_DEPTH) {
            throw OperationRejected::because('folder_too_deep');
        }

        $folder = new MediaFolder();
        $folder->name = $name;
        $folder->parent_id = $parent?->getKey();
        $folder->slug = $this->tree->uniqueSlug($name, $parent?->getKey());

        foreach (['owner_type', 'owner_id', 'visibility', 'scope_key'] as $field) {
            if (array_key_exists($field, $context)) {
                $folder->setAttribute($field, $context[$field]);
            }
        }

        $this->tree->materialize($folder, $parent);

        $folder->save();

        $this->events->dispatch(new FolderCreated($folder));

        return $folder;
    }
}
