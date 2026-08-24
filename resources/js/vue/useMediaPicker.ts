import { computed, ref, shallowRef } from 'vue'
import type { ComputedRef, Ref, ShallowRef } from 'vue'
import type { Media, MediaType } from '../client'

export interface MediaPickerRequest {
    multiple: boolean
    types: readonly MediaType[]
    title: string | null
}

export interface UseMediaPicker {
    open: Ref<boolean>
    request: ShallowRef<MediaPickerRequest | null>
    multiple: ComputedRef<boolean>

    /**
     * Opens the picker and resolves with what was chosen — an empty array when the person
     * dismissed it.
     */
    pick(options?: Partial<MediaPickerRequest>): Promise<Media[]>

    /** Called by whatever screen is displaying the picker. */
    choose(media: Media | readonly Media[]): void
    cancel(): void
}

/**
 * A PICKER THAT RETURNS A PROMISE.
 *
 * ⚠️ A PROMISE, NOT AN EVENT, AND THAT IS THE WHOLE ERGONOMICS OF IT. `const [cover] = await
 * pick()` reads in one line at the place where the choice is needed. An event forces the caller
 * to hold state between "I opened it" and "something came back", and every screen reinvents that
 * bookkeeping — usually forgetting the dismissal.
 *
 * ⚠️ AND A DISMISSAL RESOLVES, IT DOES NOT REJECT. Closing a picker is a normal thing to do, not
 * an error: rejecting would make every caller wrap the call in a `try` to handle the most
 * ordinary outcome there is.
 *
 * ⚠️ THIS COMPOSABLE DRAWS NOTHING. It holds the request and the answer; the modal is layer 3.
 * That separation is what lets a host use its own dialog and still get `await pick()`.
 */
export function useMediaPicker(): UseMediaPicker {
    const open = ref(false)
    const request = shallowRef<MediaPickerRequest | null>(null)

    let settle: ((chosen: Media[]) => void) | null = null

    function finish(chosen: Media[]): void {
        const resolve = settle

        settle = null
        open.value = false
        request.value = null

        resolve?.(chosen)
    }

    return {
        open,
        request,

        multiple: computed(() => request.value?.multiple ?? false),

        pick(options: Partial<MediaPickerRequest> = {}): Promise<Media[]> {
            /*
             * ⚠️ OPENING TWICE RESOLVES THE FIRST CALL RATHER THAN LEAVING IT HANGING. A promise
             * that never settles is a screen that waits forever, and the caller has no way to
             * know it happened.
             */
            finish([])

            request.value = {
                multiple: options.multiple ?? false,
                types: options.types ?? [],
                title: options.title ?? null,
            }

            open.value = true

            return new Promise<Media[]>((resolve) => {
                settle = resolve
            })
        },

        choose(media: Media | readonly Media[]): void {
            const chosen = Array.isArray(media) ? [...media] : [media as Media]

            finish(request.value?.multiple === true ? chosen : chosen.slice(0, 1))
        },

        cancel(): void {
            finish([])
        },
    }
}
