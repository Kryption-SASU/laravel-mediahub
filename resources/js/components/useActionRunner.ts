import { ref, shallowRef, toValue } from 'vue'
import type { MaybeRefOrGetter, Ref, ShallowRef } from 'vue'
import { MediaHubError } from '../client'
import type { Selection } from '../client'
import type { MhAction } from './actions'

export interface UseActionRunner {
    /** The action waiting for an answer, or `null`. */
    pending: ShallowRef<MhAction | null>
    running: Ref<boolean>
    error: ShallowRef<MediaHubError | null>

    /** Runs it, or asks first when the action says to. */
    request(action: MhAction): Promise<void>
    confirm(): Promise<void>
    cancel(): void
}

/**
 * RUNNING AN ACTION, INCLUDING THE PART WHERE IT ASKS FIRST.
 *
 * ⚠️ THE ASKING LIVES HERE, NOT IN EACH TOOLBAR. Two renderers offering the same actions must
 * also guard them the same way: a context menu that deletes without asking, beside a bar that
 * asks, is a difference nobody documents and everybody discovers once.
 *
 * ⚠️ AND THE SELECTION IS READ WHEN THE ACTION RUNS, not when it was requested. Between the
 * click and the answer somebody can tick another file; capturing it early would delete what was
 * selected a dialog ago, which is exactly the sort of thing a confirmation is supposed to
 * prevent.
 */
export function useActionRunner(
    selection: MaybeRefOrGetter<Selection>,
    onDone?: (action: MhAction) => void,
): UseActionRunner {
    const pending = shallowRef<MhAction | null>(null)
    const running = ref(false)
    const error = shallowRef<MediaHubError | null>(null)

    async function perform(action: MhAction): Promise<void> {
        running.value = true
        error.value = null

        try {
            await action.run(toValue(selection))
            onDone?.(action)
        } catch (thrown) {
            /*
             * ⚠️ ANYTHING THAT IS NOT A REFUSAL IS WRAPPED RATHER THAN RE-THROWN. A dropped
             * connection escaping from here becomes an unhandled rejection in the console and
             * nothing on screen; wrapped, it reaches the same place every other failure does.
             */
            error.value =
                thrown instanceof MediaHubError
                    ? thrown
                    : new MediaHubError(0, null, 'The action could not be carried out.')
        } finally {
            running.value = false
        }
    }

    return {
        pending,
        running,
        error,

        async request(action: MhAction): Promise<void> {
            if (action.confirm) {
                pending.value = action

                return
            }

            await perform(action)
        },

        async confirm(): Promise<void> {
            const action = pending.value

            pending.value = null

            if (action) {
                await perform(action)
            }
        },

        cancel(): void {
            pending.value = null
        },
    }
}
