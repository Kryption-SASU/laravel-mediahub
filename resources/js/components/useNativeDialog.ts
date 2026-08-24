import { watch } from 'vue'
import type { MaybeRefOrGetter, Ref } from 'vue'
import { toValue } from 'vue'

/**
 * SHOWING AND HIDING A NATIVE `<dialog>`, WITHOUT THE FOUR USUAL MISTAKES.
 *
 * ⚠️ `showModal()` IS WHY THE ELEMENT IS NATIVE AT ALL. It brings the focus trap, the Escape
 * key, inertness of everything behind it and the top layer — four things a hand-rolled modal
 * gets wrong in the same four ways every time, and which nobody notices until somebody navigates
 * with a keyboard. It also stops the dialog being clipped by an ancestor's `overflow: hidden`,
 * which is how modals end up half visible inside a scrolling panel.
 *
 * ⚠️ THE CALLS ARE GUARDED, BECAUSE NOT EVERY DOCUMENT HAS THEM. Test environments and some
 * embedded browsers expose `<dialog>` without its methods; calling them blind throws during a
 * render and takes the whole screen down — to avoid showing a prompt.
 */
export function useNativeDialog(
    element: Ref<HTMLDialogElement | null>,
    isOpen: MaybeRefOrGetter<boolean>,
): void {
    watch(
        () => [toValue(isOpen), element.value] as const,
        ([open, dialog]) => {
            if (!dialog) {
                return
            }

            if (open && !dialog.open) {
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal()
                } else {
                    dialog.setAttribute('open', '')
                }
            }

            if (!open && dialog.open) {
                if (typeof dialog.close === 'function') {
                    dialog.close()
                } else {
                    dialog.removeAttribute('open')
                }
            }
        },
        /*
         * ⚠️ AFTER THE RENDER, NECESSARILY: the element does not exist before it. That deferral
         * is also why nothing else in a dialog may key off this moment — an answer given in
         * between must not be undone by the effect that finally displays the question.
         */
        { immediate: true, flush: 'post' },
    )
}
