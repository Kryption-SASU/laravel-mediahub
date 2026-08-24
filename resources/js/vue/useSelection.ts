import { computed, ref } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import type { Folder, Media, Selection } from '../client'

export type SelectableKind = 'media' | 'folder'

export interface UseSelection {
    media: Ref<string[]>
    folders: Ref<string[]>
    count: ComputedRef<number>
    empty: ComputedRef<boolean>

    isSelected(kind: SelectableKind, id: string): boolean
    select(kind: SelectableKind, id: string): void
    deselect(kind: SelectableKind, id: string): void
    toggle(kind: SelectableKind, id: string): void
    replace(items: { media?: readonly Media[]; folders?: readonly Folder[] }): void
    clear(): void

    /** What the client's batch operations expect. */
    asSelection(): Selection
}

/**
 * WHAT IS SELECTED — and it holds identifiers, not objects.
 *
 * ⚠️ KEEPING THE MODELS WOULD MAKE THE SELECTION GO STALE. A file renamed, moved or trashed while
 * it is selected leaves a copy of its old self in the selection, and the screen then acts on
 * something that no longer exists in that shape. Identifiers survive a refresh; objects do not.
 *
 * ⚠️ AND MEDIA AND FOLDERS ARE KEPT APART, like everywhere else in this package. A single list
 * with a kind flag makes the caller decide which table is searched, which is the shape the server
 * refuses on purpose.
 */
export function useSelection(): UseSelection {
    const media = ref<string[]>([])
    const folders = ref<string[]>([])

    function bucket(kind: SelectableKind): Ref<string[]> {
        return kind === 'media' ? media : folders
    }

    function isSelected(kind: SelectableKind, id: string): boolean {
        return bucket(kind).value.includes(id)
    }

    function select(kind: SelectableKind, id: string): void {
        if (!isSelected(kind, id)) {
            bucket(kind).value = [...bucket(kind).value, id]
        }
    }

    function deselect(kind: SelectableKind, id: string): void {
        bucket(kind).value = bucket(kind).value.filter((candidate) => candidate !== id)
    }

    return {
        media,
        folders,

        count: computed(() => media.value.length + folders.value.length),
        empty: computed(() => media.value.length === 0 && folders.value.length === 0),

        isSelected,
        select,
        deselect,

        toggle(kind: SelectableKind, id: string): void {
            if (isSelected(kind, id)) {
                deselect(kind, id)
            } else {
                select(kind, id)
            }
        },

        replace(items: { media?: readonly Media[]; folders?: readonly Folder[] }): void {
            media.value = (items.media ?? []).map((item) => item.id)
            folders.value = (items.folders ?? []).map((item) => item.id)
        },

        clear(): void {
            media.value = []
            folders.value = []
        },

        /**
         * ⚠️ AN EMPTY LIST IS OMITTED, NOT SENT EMPTY. The server refuses a batch that names
         * nothing, and it is right to: an action that "succeeds" without doing anything hides a
         * screen that lost its selection.
         */
        asSelection(): Selection {
            const selection: Selection = {}

            if (media.value.length > 0) {
                selection.media = [...media.value]
            }

            if (folders.value.length > 0) {
                selection.folders = [...folders.value]
            }

            return selection
        },
    }
}
