import { describe, expect, it } from 'vitest'
import { createMediaHubClient } from './client'
import { MediaHubError } from './errors'

interface Recorded {
    url: string
    method: string
    headers: Record<string, string>
    body: unknown
}

function stub(answers: Array<{ status?: number; body?: unknown; text?: string }> = [{ body: {} }]) {
    const calls: Recorded[] = []
    let index = 0

    const fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
        const answer = answers[Math.min(index++, answers.length - 1)] ?? { body: {} }

        calls.push({
            url: String(input),
            method: init?.method ?? 'GET',
            headers: { ...((init?.headers ?? {}) as Record<string, string>) },
            body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : null,
        })

        return new Response(answer.text ?? JSON.stringify(answer.body ?? {}), {
            status: answer.status ?? 200,
            headers: { 'Content-Type': 'application/json' },
        })
    }) as typeof globalThis.fetch

    return { fetch, calls }
}

function client(answers: Array<{ status?: number; body?: unknown; text?: string }> = [{ body: {} }]) {
    const transport = stub(answers)

    return {
        api: createMediaHubClient({ baseUrl: '/media/', csrfToken: 'tok', fetch: transport.fetch }),
        calls: transport.calls,
    }
}

describe('the request itself', () => {
    /**
     * ⚠️ WITHOUT `Accept: application/json`, A VALIDATION FAILURE INSIDE THE `web` GROUP IS A 302.
     * The client would follow the redirect, parse a page of HTML and report something unrelated
     * to what actually went wrong.
     */
    it('asks for JSON and carries the CSRF token', async () => {
        const { api, calls } = client()

        await api.quota()

        expect(calls[0]?.headers['Accept']).toBe('application/json')
        expect(calls[0]?.headers['X-CSRF-TOKEN']).toBe('tok')
    })

    /** ⚠️ THE TOKEN IS ROTATED ON LOGIN: a value read once at construction goes stale. */
    it('reads the token again on every call when given a function', async () => {
        const transport = stub([{ body: {} }, { body: {} }])
        let current = 'first'

        const api = createMediaHubClient({
            baseUrl: '/media',
            csrfToken: () => current,
            fetch: transport.fetch,
        })

        await api.quota()
        current = 'second'
        await api.quota()

        expect(transport.calls[0]?.headers['X-CSRF-TOKEN']).toBe('first')
        expect(transport.calls[1]?.headers['X-CSRF-TOKEN']).toBe('second')
    })

    it('sends no CSRF header when there is no token', async () => {
        const transport = stub()
        const api = createMediaHubClient({ baseUrl: '/media', fetch: transport.fetch })

        await api.quota()

        expect(transport.calls[0]?.headers['X-CSRF-TOKEN']).toBeUndefined()
    })

    it('joins the base URL without doubling the slash', async () => {
        const { api, calls } = client()

        await api.quota()

        expect(calls[0]?.url).toBe('/media/quota')
    })
})

describe('browsing', () => {
    it('folds the envelope into one page', async () => {
        const { api } = client([
            {
                body: {
                    data: { folder: null, breadcrumbs: [], folders: [], media: [] },
                    meta: { current_page: 1, last_page: 1, per_page: 24, total: 0 },
                },
            },
        ])

        const page = await api.browse()

        expect(page.meta.per_page).toBe(24)
        expect(page.folder).toBeNull()
    })

    it('sends each type as its own parameter', async () => {
        const { api, calls } = client()

        await api.browse({ types: ['image', 'video'] })

        expect(calls[0]?.url).toContain('types%5B%5D=image')
        expect(calls[0]?.url).toContain('types%5B%5D=video')
    })

    /** ⚠️ AN UNSET FILTER MUST NOT TRAVEL AS AN EMPTY ONE — the server would read it as a value. */
    it('omits what was not asked for', async () => {
        const { api, calls } = client()

        await api.browse({ page: 2 })

        expect(calls[0]?.url).toBe('/media?page=2')
    })

    it('sends trashed only when it is wanted', async () => {
        const { api, calls } = client([{ body: {} }, { body: {} }])

        await api.browse({ trashed: false })
        await api.browse({ trashed: true })

        expect(calls[0]?.url).not.toContain('trashed')
        expect(calls[1]?.url).toContain('trashed=1')
    })
})

describe('changing things', () => {
    /**
     * ⚠️ THE DISTINCTION THAT COSTS A FILE ITS FOLDER. Present and `null` means "move to the
     * root"; absent means "do not move". A client that collapses the two turns every rename into
     * a move, and nothing in the response says so.
     */
    it('tells a rename apart from a move to the root', async () => {
        const { api, calls } = client([{ body: { data: {} } }, { body: { data: {} } }])

        await api.update('m1', { name: 'Balance' })
        await api.update('m1', { name: 'Balance', folder: null })

        expect(calls[0]?.body).toEqual({ name: 'Balance' })
        expect(calls[1]?.body).toEqual({ name: 'Balance', folder: null })
    })

    it('does the same for a folder', async () => {
        const { api, calls } = client([{ body: { data: {} } }, { body: { data: {} } }])

        await api.createFolder('Invoices')
        await api.createFolder('Invoices', null)

        expect(calls[0]?.body).toEqual({ name: 'Invoices' })
        expect(calls[1]?.body).toEqual({ name: 'Invoices', parent: null })
    })

    it('posts a copy and unwraps the media', async () => {
        const { api, calls } = client([{ status: 201, body: { data: { id: 'm2' } } }])

        const copy = await api.copy('m1', 'f1')

        expect(calls[0]?.method).toBe('POST')
        expect(calls[0]?.url).toBe('/media/m1/copy')
        expect(copy.id).toBe('m2')
    })
})

describe('batches', () => {
    it('sends only the lists that were given', async () => {
        const { api, calls } = client([{ body: { data: { count: 1 } } }])

        await api.trash({ media: ['a'] })

        expect(calls[0]?.body).toEqual({ media: ['a'] })
    })

    it('empties the trash with a DELETE and no body', async () => {
        const { api, calls } = client([{ body: { data: { count: 3 } } }])

        const result = await api.emptyTrash()

        expect(calls[0]?.method).toBe('DELETE')
        expect(calls[0]?.body).toBeNull()
        expect(result.count).toBe(3)
    })
})

describe('archives', () => {
    /**
     * ⚠️ NO METHOD RETURNS THE ARCHIVE AS A BLOB, and this test is what keeps it that way. A blob
     * puts the whole archive in the tab's memory — precisely what streaming it was built to
     * avoid, and it fails on the archives that most need to work.
     */
    it('describes the request instead of performing it', () => {
        const { api, calls } = client()

        const request = api.archiveRequest({ media: ['a', 'b'] }, 'selection.zip')

        expect(request.url).toBe('/media/archive')

        /*
         * ⚠️ THE BRACKETS ARE PART OF THE NAME, AND ONLY ON THE LISTS. `media[]` is what makes
         * PHP read repeated fields as a list; `name` must not carry them or the server receives
         * a one-element array where it expects a string. The form that writes these cannot tell
         * one from the other, and appending `[]` to everything is what it used to do — wrong on
         * every scalar, and quiet about it because nothing sent one.
         */
        expect(request.fields).toEqual({ 'media[]': ['a', 'b'], name: ['selection.zip'] })
        expect(calls).toHaveLength(0)
    })
})

describe('refusals', () => {
    it('carries the stable reason', async () => {
        const { api } = client([{ status: 422, body: { reason: 'archive_empty', message: 'Nothing.' } }])

        await expect(api.trash({ media: ['a'] })).rejects.toMatchObject({
            status: 422,
            reason: 'archive_empty',
        })
    })

    /** ⚠️ A FRAMEWORK VALIDATION FAILURE HAS NO `reason`, and inventing one would circulate a key nobody sends. */
    it('keeps the fields of a validation failure and no reason', async () => {
        const { api } = client([
            { status: 422, body: { message: 'Invalid.', errors: { name: ['required'] } } },
        ])

        const failure = await api.createFolder('').catch((error: unknown) => error)

        expect(failure).toBeInstanceOf(MediaHubError)
        expect((failure as MediaHubError).reason).toBeNull()
        expect((failure as MediaHubError).invalid).toBe(true)
        expect((failure as MediaHubError).validation).toEqual({ name: ['required'] })
    })

    /** ⚠️ A GATEWAY RETURNING HTML IS STILL AN ERROR, and it must not be read as an empty object. */
    it('survives a body that is not JSON', async () => {
        const { api } = client([{ status: 504, text: '<html>gateway timeout</html>' }])

        const failure = (await api.quota().catch((error: unknown) => error)) as MediaHubError

        expect(failure.status).toBe(504)
        expect(failure.reason).toBeNull()
        expect(failure.message).toContain('504')
    })

    it('names 404 and 403 for what they are', async () => {
        const notFound = new MediaHubError(404, 'item_not_found', 'Gone.')
        const forbidden = new MediaHubError(403, null, 'No.')

        expect(notFound.notFound).toBe(true)
        expect(notFound.forbidden).toBe(false)
        expect(forbidden.forbidden).toBe(true)
        expect(forbidden.invalid).toBe(false)
    })
})
