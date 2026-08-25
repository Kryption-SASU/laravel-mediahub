/**
 * HANDING A FILE TO THE BROWSER TO SAVE.
 *
 * ⚠️ AN ANCHOR THAT IS CLICKED, NOT `location.href`. Assigning the address works only while the
 * server answers with an attachment header; the day one of them serves an image inline — and
 * that is the ordinary case for a disk that signs its own URLs — the library disappears and the
 * file fills the window, with the back button as the only way home.
 *
 * ⚠️ THE FILE NAME IS CARRIED, because the address rarely holds it. A signed URL ends in a
 * signature, a CDN in a hash: without this, a download of `facture.pdf` lands in the folder as
 * `a7f3c9e2`, which is the sort of thing nobody reports and everybody works around.
 *
 * ⚠️ AND THE ANCHOR IS PUT IN THE DOCUMENT BEFORE IT IS CLICKED. Some browsers ignore a click on
 * an element that is not in the tree, and the ones that do not are the ones people test on.
 */
export function startDownload(url: string, filename?: string | null): void {
    if (typeof document === 'undefined') {
        return
    }

    const anchor = document.createElement('a')

    anchor.href = url
    anchor.rel = 'noopener'

    if (filename) {
        anchor.download = filename
    }

    /* ⚠️ OUT OF SIGHT, NOT OUT OF THE TREE. */
    anchor.style.position = 'fixed'
    anchor.style.top = '-1000px'

    document.body.appendChild(anchor)

    try {
        anchor.click()
    } finally {
        anchor.remove()
    }
}
