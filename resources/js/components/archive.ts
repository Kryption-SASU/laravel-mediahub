import type { MediaHubClient, Selection } from '../client'

/**
 * ASKING FOR AN ARCHIVE, WITHOUT EVER HOLDING IT.
 *
 * ⚠️ NOT `fetch()` FOLLOWED BY `blob()`, AND THAT IS THE WHOLE REASON THIS FILE EXISTS. The
 * server streams the ZIP precisely so that nothing anywhere holds it: reading the answer into a
 * blob puts the entire archive back into the tab's memory, and it fails on exactly the archives
 * that most needed streaming. The request is therefore made by the browser's own download
 * machinery — a form submission — and nothing in JavaScript ever sees the bytes.
 *
 * ⚠️ AND IT IS SUBMITTED INTO A HIDDEN FRAME RATHER THAN A NEW TAB. A tab is the usual advice and
 * it works, right up to a refusal: an archive beyond what the machine can finish answers 422 with
 * a JSON body, which then fills a blank tab with `{"reason":"archive_beyond_capacity"}`. Same
 * origin means the frame's document can be read, so a refusal comes back here and is shown where
 * the person is looking, while a real archive leaves the frame empty and the browser saves it.
 *
 * ⚠️ THE FRAME IS NOT REMOVED WHEN THE DOWNLOAD STARTS. Removing it mid-transfer cancels it in
 * some browsers, so it is taken out on the next request instead — one element per screen, reused.
 */
const FRAME_NAME = 'mh-archive'

export interface ArchiveOutcome {
    /** The server's refusal, when it refused. `null` means the download was handed to the browser. */
    reason: string | null
}

export function requestArchive(
    client: MediaHubClient,
    selection: Selection,
    name?: string,
): Promise<ArchiveOutcome> {
    if (typeof document === 'undefined') {
        return Promise.resolve({ reason: null })
    }

    const request = client.archiveRequest(selection, name)
    const frame = borrowFrame()
    const form = document.createElement('form')

    form.method = 'post'
    form.action = request.url
    form.target = FRAME_NAME
    form.style.display = 'none'

    for (const [field, values] of Object.entries(request.fields)) {
        for (const value of values) {
            /* ⚠️ `media[]` RATHER THAN `media`. A form sends one value per name; the brackets are
             * what makes PHP read repeated fields as a list, and without them a selection of five
             * files arrives as one. */
            form.appendChild(hidden(field + '[]', value))
        }
    }

    /*
     * ⚠️ THE TOKEN TRAVELS AS A FIELD, because a form cannot carry a header. The client already
     * knows where to find it — it puts the same value on every request it makes itself — so it is
     * read from there rather than looked up a second way that could disagree.
     */
    const token = client.headers()['X-CSRF-TOKEN']

    if (token !== undefined) {
        form.appendChild(hidden('_token', token))
    }

    return new Promise<ArchiveOutcome>((settle) => {
        frame.onload = (): void => {
            settle({ reason: refusalIn(frame) })
        }

        document.body.appendChild(form)
        form.submit()
        form.remove()
    })
}

/**
 * ⚠️ A REFUSAL LEAVES A DOCUMENT BEHIND; AN ARCHIVE LEAVES NOTHING. The browser handles an
 * attachment without navigating the frame, so an empty — or unreadable — document is the success
 * case and is reported as one.
 */
function refusalIn(frame: HTMLIFrameElement): string | null {
    try {
        const text = frame.contentDocument?.body?.textContent?.trim() ?? ''

        if (text === '') {
            return null
        }

        const answer: unknown = JSON.parse(text)

        return typeof answer === 'object' && answer !== null && 'reason' in answer
            ? String((answer as { reason: unknown }).reason)
            : null
    } catch {
        /* Not a document we can read, or not JSON: nothing was refused in a way we understand. */
        return null
    }
}

function borrowFrame(): HTMLIFrameElement {
    document.querySelector('iframe[name="' + FRAME_NAME + '"]')?.remove()

    const frame = document.createElement('iframe')

    frame.name = FRAME_NAME
    frame.setAttribute('aria-hidden', 'true')
    frame.style.display = 'none'

    document.body.appendChild(frame)

    return frame
}

function hidden(name: string, value: string): HTMLInputElement {
    const field = document.createElement('input')

    field.type = 'hidden'
    field.name = name
    field.value = value

    return field
}
