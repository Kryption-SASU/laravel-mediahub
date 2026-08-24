import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import type { BrowsePage, MediaHubClient } from '../client'
import { resolveMediaHub } from './context'
import { deferred, fakeClient, folder, media } from './fake.test-utils'
import { useMediaBrowser } from './useMediaBrowser'

describe('browsing', () => {
    it('holds the page it was given', async () => {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1')], folders: [folder('f1')], folder: folder('f1') })

        const browser = useMediaBrowser(api)
        await browser.refresh()

        expect(browser.page.value?.media).toHaveLength(1)
        expect(browser.folder.value?.id).toBe('f1')
    })

    it('accepts a folder, its id, or the root', async () => {
        const api = fakeClient()
        const browser = useMediaBrowser(api)

        await browser.open(folder('f1'))
        await browser.open('f2')
        await browser.open(null)

        expect(api.calls.map((call) => (call.args[0] as { folder?: unknown }).folder)).toEqual([
            'f1',
            'f2',
            null,
        ])
    })

    /**
     * ⚠️ A FILTER CHANGE RETURNS TO PAGE ONE. Keeping the page lands the person on page seven of
     * a result that has three — an empty screen, from a search that found plenty.
     */
    it('returns to the first page when a filter changes', async () => {
        const api = fakeClient()
        const browser = useMediaBrowser(api)

        await browser.goToPage(7)
        await browser.search('invoice')

        expect(browser.query.value.page).toBe(1)
    })

    /** ⚠️ AND CHANGING THE PAGE IS THE ONE THING THAT MUST NOT RESET IT. */
    it('keeps the page when the page is what changed', async () => {
        const api = fakeClient()
        const browser = useMediaBrowser(api)

        await browser.search('invoice')
        await browser.goToPage(3)

        expect(browser.query.value.page).toBe(3)
    })

    it('turns an empty search back into nothing', async () => {
        const api = fakeClient()
        const browser = useMediaBrowser(api)

        await browser.search('')

        expect((api.calls[0]?.args[0] as { search?: unknown }).search).toBeNull()
    })

    it('carries the sort and its direction', async () => {
        const api = fakeClient()
        const browser = useMediaBrowser(api)

        await browser.sortBy('size', 'asc')

        expect(api.calls[0]?.args[0]).toMatchObject({ sort: 'size', direction: 'asc' })
    })
})

describe('two searches racing', () => {
    /**
     * ⚠️ THE SLOWER REQUEST IS NOT ALWAYS THE OLDER ONE. Typing quickly, the answer for "inv" can
     * land after the answer for "invoice" and overwrite it: the screen then shows results for
     * something the person finished typing two keystrokes ago, and nothing looks broken.
     */
    it('keeps the answer to the last question, not the last answer', async () => {
        const first: BrowsePage = {
            folder: null,
            breadcrumbs: [],
            folders: [],
            media: [media('stale')],
            meta: { current_page: 1, last_page: 1, per_page: 24, total: 1 },
        }

        const second: BrowsePage = { ...first, media: [media('fresh')] }

        /*
         * ⚠️ A `let` ASSIGNED ONLY INSIDE A CALLBACK IS NARROWED TO `null` AT THE CALL SITE, and
         * the compiler is right to: it cannot know the callback ran. Holding the resolver in a
         * promise built up front keeps the type honest without a non-null assertion.
         */
        const slowAnswer = deferred<BrowsePage>()

        const api: MediaHubClient = {
            ...fakeClient(),
            browse: (query) => {
                const term = (query as { search?: string | null } | undefined)?.search

                return term === 'inv' ? slowAnswer.promise : Promise.resolve(second)
            },
        }

        const browser = useMediaBrowser(api)

        const slow = browser.search('inv')
        const quick = browser.search('invoice')

        await quick
        slowAnswer.resolve(first)
        await slow

        expect(browser.page.value?.media[0]?.id).toBe('fresh')
    })
})

describe('when it goes wrong', () => {
    it('keeps the refusal where a screen can show it', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(404, 'item_not_found', 'Gone.'))

        const browser = useMediaBrowser(api)
        await browser.refresh()

        expect(browser.error.value?.reason).toBe('item_not_found')
        expect(browser.loading.value).toBe(false)
    })

    /** ⚠️ A NETWORK FAILURE IS NOT A `MediaHubError`, and it must not escape as an unknown throw. */
    it('wraps anything that is not a refusal', async () => {
        const api: MediaHubClient = {
            ...fakeClient(),
            browse: () => Promise.reject(new Error('offline')),
        }

        const browser = useMediaBrowser(api)
        await browser.refresh()

        expect(browser.error.value).toBeInstanceOf(MediaHubError)
        expect(browser.error.value?.status).toBe(0)
    })

    it('clears the previous refusal on the next try', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(500, null, 'Boom.'))

        const browser = useMediaBrowser(api)
        await browser.refresh()

        api.failWith(null)
        await browser.refresh()

        expect(browser.error.value).toBeNull()
    })
})

describe('without a client', () => {
    /** ⚠️ THE MESSAGE HAS TO SAY WHAT TO DO — `undefined is not a function` does not. */
    it('says how to provide one', () => {
        expect(() => resolveMediaHub()).toThrowError(/provideMediaHub/)
    })
})
