import type { Folder, Media, MediaHubClient } from '../client'

/**
 * WHAT IS BEING RENAMED — one file or one folder, said outright.
 *
 * ⚠️ THE KIND IS CARRIED RATHER THAN GUESSED. A file and a folder are renamed through different
 * endpoints, and telling them apart by looking for a `mime_type` works until the day a host's
 * folder resource grows one. A screen that knows perfectly well what it right-clicked has no
 * business hiding it from the component it hands it to.
 */
export interface MhRenameTarget {
    kind: 'media' | 'folder'
    id: string
    name: string
}

/**
 * ⚠️ ONE PLACE DECIDES WHICH ENDPOINT, and it is not a template. The alternative is a ternary
 * inside a submit handler, where the two halves are a line apart and only one of them is ever
 * exercised by the bench somebody wrote in a hurry.
 */
export function renameTo(
    client: MediaHubClient,
    target: MhRenameTarget,
    name: string,
): Promise<Media | Folder> {
    return target.kind === 'media'
        ? client.update(target.id, { name })
        : client.updateFolder(target.id, { name })
}

/** ⚠️ WHAT THE SCREEN HANDS OVER, from the thing it already has on display. */
export function targetOf(item: Media | Folder, kind: 'media' | 'folder'): MhRenameTarget {
    return { kind, id: item.id, name: item.name }
}
