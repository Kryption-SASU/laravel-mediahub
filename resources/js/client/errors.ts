/**
 * A REFUSAL FROM THE SERVER, WITH THE PART A PROGRAM CAN BRANCH ON.
 *
 * ⚠️ `reason` IS THE CONTRACT, `message` IS A COURTESY. The server guarantees the key never
 * changes and is never translated — a test on its side proves both locales carry exactly the
 * same set. A client that branches on the sentence instead breaks the day someone improves the
 * wording, or the day a user switches language.
 *
 * ⚠️ AND VALIDATION FAILURES DO NOT CARRY ONE. Laravel's own 422 answers `{message, errors}`
 * with no `reason`, because it comes from the framework rather than from this package. Pretending
 * otherwise — inventing a reason here — would put a key in circulation that the server never
 * sends and that no translation covers.
 */
export class MediaHubError extends Error {
    constructor(
        readonly status: number,
        /** The stable key, or `null` for a framework validation failure. */
        readonly reason: string | null,
        message: string,
        /** Field errors, present only on a validation failure. */
        readonly validation: Readonly<Record<string, string[]>> | null = null,
    ) {
        super(message)
        this.name = 'MediaHubError'
    }

    /** Did the server refuse this because of the scope — or because it truly does not exist? */
    get notFound(): boolean {
        return this.status === 404
    }

    get forbidden(): boolean {
        return this.status === 403
    }

    get invalid(): boolean {
        return this.validation !== null
    }

    /**
     * ⚠️ A BODY THAT IS NOT JSON IS STILL AN ERROR, and it must not become one of ours. A proxy
     * returning HTML, a gateway timeout, a request cut by the front-end server: reading those as
     * `{}` and reporting "no reason" hides what happened. The status is kept, and the text is
     * used as the message.
     */
    static async fromResponse(response: Response): Promise<MediaHubError> {
        let body: unknown = null

        try {
            body = await response.json()
        } catch {
            return new MediaHubError(
                response.status,
                null,
                `The server answered ${response.status} with a body that is not JSON.`,
            )
        }

        const payload = (body ?? {}) as {
            reason?: unknown
            message?: unknown
            errors?: unknown
        }

        const reason = typeof payload.reason === 'string' ? payload.reason : null
        const message =
            typeof payload.message === 'string' && payload.message !== ''
                ? payload.message
                : `The server answered ${response.status}.`

        const validation =
            payload.errors !== null && typeof payload.errors === 'object'
                ? (payload.errors as Record<string, string[]>)
                : null

        return new MediaHubError(response.status, reason, message, validation)
    }
}
