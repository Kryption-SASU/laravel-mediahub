import { ref } from 'vue'
import type { Ref } from 'vue'
import { MediaHubError } from '../client'
import type { Folder, MediaHubClient } from '../client'
import { resolveMediaHub } from './context'

export interface UseFolders {
    running: Ref<boolean>
    error: Ref<MediaHubError | null>

    create(name: string, parent?: Folder | string | null): Promise<Folder>
    rename(folder: Folder | string, name: string): Promise<Folder>
    move(folder: Folder | string, parent: Folder | string | null): Promise<Folder>
}

function idOf(value: { id: string } | string): string {
    return typeof value === 'string' ? value : value.id
}

/**
 * THE FOLDER OPERATIONS.
 *
 * ⚠️ RENAMING AND MOVING ARE SEPARATE METHODS THOUGH THE ROUTE IS ONE. The server distinguishes
 * them by the presence of the `parent` key, and a single `update(folder, changes)` here would
 * hand that subtlety to every caller — the one who passes `{name, parent: undefined}` from a
 * form object then sends a rename that also moves the branch to the root.
 */
export function useFolders(client?: MediaHubClient): UseFolders {
    const api = resolveMediaHub(client)

    const running = ref(false)
    const error = ref<MediaHubError | null>(null)

    async function run<T>(operation: () => Promise<T>): Promise<T> {
        running.value = true
        error.value = null

        try {
            return await operation()
        } catch (failure) {
            error.value =
                failure instanceof MediaHubError
                    ? failure
                    : new MediaHubError(0, null, 'The library could not be reached.')

            throw error.value
        } finally {
            running.value = false
        }
    }

    return {
        running,
        error,

        create(name, parent) {
            return run(() =>
                parent === undefined
                    ? api.createFolder(name)
                    : api.createFolder(name, parent === null ? null : idOf(parent)),
            )
        },

        /** ⚠️ NO `parent` IN THE PAYLOAD: a rename must not move anything. */
        rename(folder, name) {
            return run(() => api.updateFolder(idOf(folder), { name }))
        },

        move(folder, parent) {
            return run(() =>
                api.updateFolder(idOf(folder), { parent: parent === null ? null : idOf(parent) }),
            )
        },
    }
}
