import { ref, shallowRef, toValue } from 'vue'
import type { MaybeRefOrGetter, Ref, ShallowRef } from 'vue'
import { MediaHubError } from '../client'
import type { Selection } from '../client'
import type { MhAction, MhConfirmation } from './actions'

export interface UseActionRunner {
    /** The action waiting for an answer, or `null`. */
    pending: ShallowRef<MhAction | null>
    /**
     * The question to put, once it has been worked out.
     *
     * ⚠️ IT IS SEPARATE FROM THE ACTION BECAUSE IT MAY HAVE HAD TO BE ASKED FOR. A confirmation
     * that names how many files sit inside a folder cannot be written in advance — only the
     * server can count them — so what a dialog renders is this, not `pending.confirm`.
     */
    asking: ShallowRef<MhConfirmation | null>
    running: Ref<boolean>
    /**
     * What is being acted on right now, or `null` between acts.
     *
     * ⚠️ A BOOLEAN WAS NOT ENOUGH, AND THE DIFFERENCE IS WHAT SOMEBODY SEES. "Something is
     * happening" can only be drawn in the middle of the screen, over everything, for an act that
     * usually takes a second; the thing that was asked about can be drawn on itself. Duplicating
     * a file gave no sign at all until the copy appeared, which on a large file reads as a menu
     * entry that does nothing.
     *
     * ⚠️ AND IT IS SET ONLY WHILE THE ACT RUNS, not while a question is being worked out. Counting
     * what a folder holds before asking is also slow and also reaches the server, but it ends in a
     * dialog: marking the folder busy first would say the deletion had started before anybody had
     * agreed to it.
     */
    busy: ShallowRef<Selection | null>
    /**
     * How far the running act has got, when it counts at all.
     *
     * ⚠️ SEPARATE FROM `busy` BECAUSE MOST ACTS HAVE NO NUMBER. A spinner is the honest picture
     * of an act that takes a moment and cannot say where it is; a bar drawn from a made-up
     * figure is worse than the spinner, because it invites somebody to wait for the end of
     * something that is not being measured.
     */
    progress: ShallowRef<{ done: number; total: number } | null>
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
    const asking = shallowRef<MhConfirmation | null>(null)
    const running = ref(false)
    const busy = shallowRef<Selection | null>(null)
    const progress = shallowRef<{ done: number; total: number } | null>(null)
    const error = shallowRef<MediaHubError | null>(null)

    async function perform(action: MhAction): Promise<void> {
        const acting = toValue(selection)

        running.value = true
        busy.value = acting
        progress.value = null
        error.value = null

        try {
            /*
             * ⚠️ THE REPORTER IS HANDED IN RATHER THAN POLLED. Only the act itself knows whether
             * it can count anything at all; one that says nothing leaves the figure null, which
             * is what the screen reads as "draw a spinner and stop pretending".
             */
            await action.run(acting, (done, total) => {
                progress.value = { done, total }
            })
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
            busy.value = null
            progress.value = null
        }
    }

    return {
        pending,
        asking,
        running,
        busy,
        progress,
        error,

        async request(action: MhAction): Promise<void> {
            if (!action.confirm) {
                await perform(action)

                return
            }

            if (typeof action.confirm !== 'function') {
                asking.value = action.confirm
                pending.value = action

                return
            }

            /*
             * ⚠️ WORKING OUT THE QUESTION IS ITSELF AN OPERATION, and it is reported as one. It
             * reaches the server, so it can be slow and it can fail; leaving `running` alone
             * would let somebody press the same action twice while the first is still counting.
             */
            running.value = true
            error.value = null

            try {
                asking.value = await action.confirm(toValue(selection))
                pending.value = action
            } catch (thrown) {
                error.value =
                    thrown instanceof MediaHubError
                        ? thrown
                        : new MediaHubError(0, null, 'The action could not be carried out.')
            } finally {
                running.value = false
            }
        },

        async confirm(): Promise<void> {
            const action = pending.value

            pending.value = null
            asking.value = null

            if (action) {
                await perform(action)
            }
        },

        cancel(): void {
            pending.value = null
            asking.value = null
        },
    }
}
