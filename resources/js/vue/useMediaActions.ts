import { ref } from 'vue'
import type { Ref } from 'vue'
import { MediaHubError } from '../client'
import type {
    AffectedCount,
    Folder,
    Media,
    MediaHubClient,
    Selection,
} from '../client'
import { resolveMediaHub } from './context'

export interface UseMediaActions {
    running: Ref<boolean>
    error: Ref<MediaHubError | null>

    rename(media: Media | string, name: string): Promise<Media>
    annotate(media: Media | string, properties: Record<string, unknown>): Promise<Media>
    move(media: Media | string, folder: Folder | string | null): Promise<Media>
    copy(media: Media | string, folder?: Folder | string | null): Promise<Media>

    trash(selection: Selection): Promise<AffectedCount>
    restore(selection: Selection): Promise<AffectedCount>
    purge(selection: Selection): Promise<AffectedCount>
    emptyTrash(): Promise<AffectedCount>
}

function idOf(value: { id: string } | string): string {
    return typeof value === 'string' ? value : value.id
}

/**
 * THE OPERATIONS A SCREEN OFFERS — one method each, and the refusal kept where it can be shown.
 *
 * ⚠️ THE ERROR IS EXPOSED AS STATE AND ALSO THROWN. A screen wants to render the last refusal
 * without wrapping every call; a caller chaining operations needs the failure to stop the chain.
 * Offering only one of the two makes half the callers write the other themselves.
 *
 * ⚠️ AND NOTHING HERE REFRESHES A LISTING. An action that reloaded the browser behind the
 * caller's back would fire a request per item of a batch, and would fight any optimistic update
 * the screen had already made. Refreshing is the screen's decision.
 */
export function useMediaActions(client?: MediaHubClient): UseMediaActions {
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

        rename(media, name) {
            return run(() => api.update(idOf(media), { name }))
        },

        annotate(media, properties) {
            return run(() => api.update(idOf(media), { properties }))
        },

        /**
         * ⚠️ `null` IS SENT, NOT OMITTED. "Move to the root" and "do not move" are told apart by
         * the presence of the key, and collapsing them here would make a move to the root do
         * nothing at all — silently, since the response is a perfectly valid media.
         */
        move(media, folder) {
            return run(() => api.update(idOf(media), { folder: folder === null ? null : idOf(folder) }))
        },

        copy(media, folder) {
            return run(() =>
                folder === undefined
                    ? api.copy(idOf(media))
                    : api.copy(idOf(media), folder === null ? null : idOf(folder)),
            )
        },

        trash(selection) {
            return run(() => api.trash(selection))
        },

        restore(selection) {
            return run(() => api.restore(selection))
        },

        purge(selection) {
            return run(() => api.purge(selection))
        },

        emptyTrash() {
            return run(() => api.emptyTrash())
        },
    }
}
