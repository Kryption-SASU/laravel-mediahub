/**
 * PUTTING A PIECE OF TEXT ON THE CLIPBOARD, ON THE MACHINES PEOPLE ACTUALLY WORK ON.
 *
 * ⚠️ `navigator.clipboard` DOES NOT EXIST OUTSIDE A SECURE CONTEXT, which is to say on every
 * `http://` development host there is. Code relying on it alone does nothing there, silently,
 * and the first report is "the copy button is broken" from the one environment everybody works
 * in. The legacy command is tried after it, and it needs a selection to act on — so there is
 * always something selected before either is attempted.
 *
 * ⚠️ IT ANSWERS WHETHER IT WORKED, AND THE ANSWER IS LOAD-BEARING. Announcing "Copied" over a
 * clipboard that refused — no secure context, no permission, the document not focused — sends
 * somebody off to paste nothing, and the failure then surfaces somewhere else entirely.
 *
 * ⚠️ AND IT LIVES HERE RATHER THAN IN THE ONE COMPONENT THAT FIRST NEEDED IT. The details panel
 * copies an address from a field; the context menu copies the same address with no field on
 * screen at all. Two implementations of a fallback this fiddly would diverge on the first
 * browser that argued, and only one of them would be under test.
 */

/**
 * @param text  what to put on the clipboard
 * @param field an input already showing the text, selected so that a keyboard route remains when
 *              both programmatic routes refuse. Absent, one is borrowed for the attempt.
 */
export async function copyText(
    text: string,
    field?: HTMLInputElement | HTMLTextAreaElement | null,
): Promise<boolean> {
    const borrowed = field ? null : lend(text)

    try {
        ;(field ?? borrowed)?.select()

        try {
            await navigator.clipboard?.writeText(text)

            if (navigator.clipboard !== undefined) {
                return true
            }
        } catch {
            /* Refused by permission, or by the document not being focused. The command below is
             * the remaining route, and a selected field is the one after that. */
        }

        return typeof document.execCommand === 'function' && document.execCommand('copy')
    } finally {
        borrowed?.remove()
    }
}

/**
 * ⚠️ THE STAND-IN IS IN THE DOCUMENT, NOT DETACHED. `execCommand('copy')` copies the document's
 * selection, and nothing detached can hold one — a textarea built and selected off-tree copies
 * whatever the page had selected before, which is usually nothing and occasionally something
 * else entirely.
 *
 * ⚠️ AND IT IS PLACED OFF-SCREEN RATHER THAN HIDDEN. `display: none` and `visibility: hidden`
 * both make a field unselectable, so the fallback would quietly stop working; a fixed position
 * above the viewport is out of sight and still real.
 */
function lend(text: string): HTMLTextAreaElement | null {
    if (typeof document === 'undefined') {
        return null
    }

    const area = document.createElement('textarea')

    area.value = text
    area.setAttribute('readonly', '')
    area.setAttribute('aria-hidden', 'true')
    area.style.position = 'fixed'
    area.style.top = '-1000px'
    area.style.opacity = '0'

    document.body.appendChild(area)

    return area
}
