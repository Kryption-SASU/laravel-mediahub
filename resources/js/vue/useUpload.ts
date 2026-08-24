import { computed, onScopeDispose, shallowRef, triggerRef } from 'vue'
import type { ComputedRef, ShallowRef } from 'vue'
import { createUploadQueue } from '../client'
import type { Media, MediaHubClient, UploadItem, UploadQueue, UploadQueueOptions } from '../client'
import { resolveMediaHub } from './context'

export interface UseUpload {
    items: ShallowRef<readonly UploadItem[]>
    uploading: ComputedRef<boolean>
    /** Mean progress across everything still in flight, between 0 and 1. */
    progress: ComputedRef<number>
    stored: ComputedRef<Media[]>
    failed: ComputedRef<UploadItem[]>

    add(files: Iterable<File>, options?: { folder?: string | null }): UploadItem[]
    abort(id: string): void
    retry(id: string): void
    clearFinished(): void

    /** The underlying queue, for anything this wrapper does not cover. */
    queue: UploadQueue
}

/**
 * THE UPLOAD QUEUE, AS REACTIVE STATE.
 *
 * ⚠️ THE QUEUE ITSELF KNOWS NOTHING OF VUE, and this is the only place that joins the two. That
 * is what lets an Angular application use exactly the same queue with its own bindings — and it
 * is checked, not merely intended: nothing under `client/` may import `vue`.
 */
export function useUpload(client?: MediaHubClient, options: UploadQueueOptions = {}): UseUpload {
    const api = resolveMediaHub(client)
    const queue = createUploadQueue(api, options)

    /*
     * ⚠️ A SHALLOW REF NUDGED BY HAND, NOT A DEEP ONE. The queue mutates its items in place —
     * that is what makes progress cheap — and a deep reactive copy would either lose those
     * mutations or re-wrap every item on each of the dozens of progress events per file.
     */
    const items = shallowRef<readonly UploadItem[]>(queue.items)

    const stop = queue.subscribe(() => {
        items.value = [...queue.items]
        triggerRef(items)
    })

    /*
     * ⚠️ AND THE SUBSCRIPTION IS RELEASED WITH THE SCOPE. A composable that leaves a listener
     * behind keeps the component alive through the closure; on a screen opened and closed all
     * day, that is a leak nobody attributes to the media library.
     */
    onScopeDispose(stop)

    const inFlight = computed(() =>
        items.value.filter((item) => item.status === 'pending' || item.status === 'uploading'),
    )

    return {
        items,
        queue,

        uploading: computed(() => inFlight.value.length > 0),

        /**
         * ⚠️ THE MEAN IS TAKEN OVER WHAT IS STILL RUNNING, and it is a deliberate simplification:
         * a bar weighted by file size would be more truthful and would also jump backwards every
         * time a new file joins. Neither is exact; this one at least never goes down.
         */
        progress: computed(() => {
            const running = inFlight.value

            if (running.length === 0) {
                return items.value.length === 0 ? 0 : 1
            }

            return running.reduce((total, item) => total + item.progress, 0) / running.length
        }),

        stored: computed(() =>
            items.value
                .filter((item) => item.media !== null)
                .map((item) => item.media as Media),
        ),

        failed: computed(() => items.value.filter((item) => item.status === 'failed')),

        add(files: Iterable<File>, addOptions: { folder?: string | null } = {}): UploadItem[] {
            return queue.enqueue(files, addOptions)
        },

        abort(id: string): void {
            queue.abort(id)
        },

        retry(id: string): void {
            queue.retry(id)
        },

        clearFinished(): void {
            queue.clearFinished()
        },
    }
}
