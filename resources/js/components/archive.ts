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
 *
 * ⚠️ AND A DOWNLOAD NEVER FIRES `load` ON THAT FRAME. This is the part that was wrong: the
 * browser cancels the frame's navigation and saves the file instead, so the event this waited
 * for simply never came. Only a refusal ever loaded a document — meaning the promise settled on
 * failure and hung on success, and the spinner stayed on the selection with the ZIP already
 * sitting finished in the downloads folder. The bench that should have caught it dispatched
 * `load` by hand, which is the one thing the browser does not do.
 *
 * ⚠️ SO SUCCESS IS HEARD THROUGH A COOKIE, the one channel a download does not close. The
 * response sets it in its headers, so it lands in the jar the moment the answer begins — see
 * `BuildArchive::STARTED_COOKIE`, whose name is fixed on both sides for exactly this reason.
 */
const FRAME_NAME = 'mh-archive'

/** Must match `BuildArchive::STARTED_COOKIE`. A test on the PHP side holds the two together. */
const STARTED_COOKIE = 'mediahub_archive_started'

/**
 * ⚠️ "THE ANSWER HAS BEGUN" IS ALL ANYONE CAN KNOW, and it is the honest thing to wait for. Once
 * the browser has taken the attachment it owns the transfer, shows its own progress and tells
 * this page nothing more — so a spinner that claimed to track the download would be guessing
 * from the first byte to the last.
 */
const POLL_MS = 150

/**
 * ⚠️ AND THE WAIT ENDS EVEN WHEN NOTHING IS EVER HEARD. A page left spinning is the bug this
 * file is fixing; repeating it in the failure case would be worse than the failure. What is not
 * known after this is reported as nothing rather than as a fault, because a download may well be
 * running perfectly behind a cookie that never arrived.
 */
const GIVE_UP_MS = 120_000

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

    /* ⚠️ CLEARED BEFORE ASKING, so what is seen afterwards is this answer and not the last one. */
    forgetStarted()

    return new Promise<ArchiveOutcome>((settle) => {
        let watch: ReturnType<typeof setInterval> | undefined
        let deadline: ReturnType<typeof setTimeout> | undefined

        const done = (outcome: ArchiveOutcome): void => {
            clearInterval(watch)
            clearTimeout(deadline)
            forgetStarted()
            settle(outcome)
        }

        /*
         * ⚠️ THE FRAME ANSWERS FOR REFUSALS, AND FOR NOTHING ELSE. A response the browser does
         * not save is one it navigates, which is the 422 with its reason in it.
         *
         * ⚠️ AN EMPTY LOAD IS NOT AN ANSWER — it used to be read as success, and that was wrong
         * twice over. A frame with no source loads `about:blank` on its own, before the form has
         * even been submitted; and a download leaves the previous document in place. Either way
         * an empty document says nothing about the request, so only the cookie reports success.
         */
        frame.onload = (): void => {
            const refusal = refusalIn(frame)

            if (refusal !== null) {
                done({ reason: refusal })
            }
        }

        watch = setInterval(() => {
            if (hasStarted()) {
                done({ reason: null })
            }
        }, POLL_MS)

        deadline = setTimeout(() => {
            done({ reason: null })
        }, GIVE_UP_MS)

        document.body.appendChild(form)
        form.submit()
        form.remove()
    })
}

/**
 * Whether the answer has begun.
 *
 * ⚠️ THE NAME IS NOT ENOUGH: A DELETED COOKIE CAN KEEP IT. Clearing one is itself a `Set-Cookie`
 * — an empty value with an expiry in the past — and until the store acts on that expiry the name
 * is still there. Matching on it alone made the next archive settle the instant it was asked
 * for, on the corpse of the previous one. Caught by a bench written to fail first.
 *
 * ⚠️ AND WHAT IS CHECKED IS THAT SOMETHING IS THERE, NEVER WHAT. A Laravel host that encrypts
 * its outgoing cookies turns the value into ciphertext this script could not match — so nothing
 * ever compares it, and any non-empty value is the message.
 */
function hasStarted(): boolean {
    return document.cookie.split(';').some((one) => {
        const [name, ...rest] = one.trim().split('=')

        return name === STARTED_COOKIE && rest.join('=') !== ''
    })
}

/** ⚠️ EMPTIED AS WELL AS EXPIRED, so that {@see hasStarted} reads "no" either way. */
function forgetStarted(): void {
    document.cookie = STARTED_COOKIE + '=; path=/; max-age=0'
}

/**
 * ⚠️ A REFUSAL LEAVES A DOCUMENT BEHIND; NOTHING ELSE HERE DOES. An empty or unreadable document
 * is not a success — it is a frame that has loaded its own `about:blank`, or one the browser
 * left alone while it saved an attachment. It answers `null`, which the caller reads as "say
 * nothing yet" rather than as "done".
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
