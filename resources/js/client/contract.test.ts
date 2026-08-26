import { describe, expect, it } from 'vitest'
import batch from '../../../tests/Fixtures/contract/batch.json'
import browseFolder from '../../../tests/Fixtures/contract/browse-folder.json'
import browseRoot from '../../../tests/Fixtures/contract/browse-root.json'
import folderFixture from '../../../tests/Fixtures/contract/folder.json'
import invalid from '../../../tests/Fixtures/contract/invalid.json'
import mediaFixture from '../../../tests/Fixtures/contract/media.json'
import notFound from '../../../tests/Fixtures/contract/not-found.json'
import quotaFixture from '../../../tests/Fixtures/contract/quota.json'
import refusal from '../../../tests/Fixtures/contract/refusal.json'
import { createMediaHubClient } from './client'
import { MediaHubError } from './errors'
import { MEDIA_TYPES } from './types'
import type { AffectedCount, BrowsePage, Folder, Media, MediaType, Quota } from './types'

/**
 * THE OTHER END OF THE BRIDGE.
 *
 * ⚠️ THE FIXTURES READ HERE ARE NOT WRITTEN HERE. They are produced by the PHP suite from real
 * responses, and committed — `tests/Feature/ContractFixturesTest.php` regenerates them and goes
 * red if a resource changes shape. A payload typed out by hand in this file would prove nothing
 * at all: it would say what the browser expects, which is the thing under test.
 *
 * ⚠️ SO THIS FILE DOES TWO DISTINCT THINGS, and both are needed. It assigns each fixture to its
 * declared type WITHOUT A CAST — a rename or a type change then fails `npm run types`, at
 * compile time, on the exact key. And it feeds each fixture through the real client, because a
 * type that accepts a payload says nothing about code that unwraps it: `browse()` rebuilds its
 * result from two halves of the body, and a compiler cannot see that.
 *
 * ⚠️ NEVER ADD A CAST TO SILENCE THIS FILE. `as Media` turns the guard off while leaving it
 * looking like it works, which is the one outcome worse than not having written it.
 */

/** A transport that answers a single committed payload, whatever is asked of it. */
function serving(body: unknown, status = 200): typeof fetch {
    return (async () =>
        new Response(JSON.stringify(body), {
            status,
            headers: { 'Content-Type': 'application/json' },
        })) as unknown as typeof fetch
}

function clientServing(body: unknown, status = 200) {
    return createMediaHubClient({ baseUrl: '/media', fetch: serving(body, status) })
}

/**
 * THE ONE PLACE A FIXTURE IS NARROWED, AND IT IS CHECKED WHILE IT HAPPENS.
 *
 * ⚠️ JSON HAS NO UNIONS. Imported, `"type": "document"` is a `string`, and no import syntax
 * narrows it — so a bare assignment cannot compile. The tempting fix, `as Media`, would switch
 * off every other key at the same time and leave the file looking like it still checks
 * something.
 *
 * ⚠️ WHAT HAPPENS INSTEAD IS STRICTLY STRONGER. The parameter type demands every key of `Media`
 * except `type`, so a renamed or retyped key still fails to compile, here, by name. And `type`
 * is confronted at runtime with the values the union is made of — which catches what the
 * compiler never could: the server sending a seventh kind the union does not know.
 */
function asMedia(raw: Omit<Media, 'type'> & { type: string }): Media {
    expect(MEDIA_TYPES).toContain(raw.type)

    return { ...raw, type: raw.type as MediaType }
}

describe('the types accept what the server actually sends', () => {
    /*
     * ⚠️ THESE ASSIGNMENTS ARE THE TEST. They look inert — nothing is asserted on them — but
     * they are checked by `tsc`, not by Vitest, and that is the point: the failure lands at
     * compile time with the key named, before a single test runs.
     */

    it('a media', () => {
        const media: Media = asMedia(mediaFixture.data)

        expect(media.folder_id).toBeNull()
        expect(media.custom_properties).toBeTypeOf('object')
    })

    it('a folder', () => {
        const created: Folder = folderFixture.data

        expect(created.depth).toBe(0)
    })

    it('a browse page, at the root and inside a folder', () => {
        const root: BrowsePage = {
            ...browseRoot.data,
            media: browseRoot.data.media.map(asMedia),
            meta: browseRoot.meta,
        }

        const inside: BrowsePage = {
            ...browseFolder.data,
            media: browseFolder.data.media.map(asMedia),
            meta: browseFolder.meta,
        }

        expect(root.folder).toBeNull()
        expect(inside.folder).not.toBeNull()
        expect(inside.breadcrumbs).toHaveLength(1)
    })

    it('a quota', () => {
        const quota: Quota = quotaFixture.data

        expect(quota.unlimited).toBe(true)
    })

    it('a batch result', () => {
        const affected: AffectedCount = batch.data

        expect(affected.count).toBe(1)
    })
})

describe('the client reads what the server actually sends', () => {
    it('rebuilds a browse page from both halves of the body', async () => {
        const page = await clientServing(browseRoot).browse()

        /*
         * ⚠️ `meta` LIVES BESIDE `data`, NOT INSIDE IT. The client rebuilds one object from the
         * two, and nothing but a real payload catches the day it stops doing so — the type
         * would still be satisfied by a page whose `meta` is quietly undefined.
         *
         * ⚠️ AND THE FIGURE IS READ FROM THE FIXTURE RATHER THAN WRITTEN HERE. It used to say
         * `24`, which was the default page size when the fixture was last regenerated; the
         * default became 48 and the fixture was not regenerated, so the two agreed with each
         * other and with nothing else for weeks. What this asserts is that the number ARRIVED,
         * which is the claim in the paragraph above — not what the number happens to be.
         */
        expect(page.meta.per_page).toBe(browseRoot.meta.per_page)
        expect(page.meta.per_page).toBeGreaterThan(0)
        expect(page.media).toHaveLength(browseRoot.data.media.length)
        expect(page.folders[0]?.name).toBe('Invoices')
    })

    it('unwraps a media, a folder, a quota and a count', async () => {
        expect((await clientServing(mediaFixture).show('m1')).name).toBe('Annual report')
        expect((await clientServing(folderFixture).createFolder('Contracts')).slug).toBe('contracts')
        expect((await clientServing(quotaFixture).quota()).used).toBe(0)
        expect((await clientServing(batch).trash({ media: ['m1'] })).count).toBe(1)
    })
})

describe('the client reads the refusals the server actually sends', () => {
    /**
     * ⚠️ `reason` IS WHAT A PROGRAM BRANCHES ON. Reading it from a hand-written payload would
     * only confirm that the client can read the payload this file wrote.
     */
    it('carries the stable key of a refusal', async () => {
        await expect(clientServing(refusal, 422).quota()).rejects.toMatchObject({
            status: 422,
            reason: 'archive_empty',
        })
    })

    it('carries the stable key of a missing item', async () => {
        await expect(clientServing(notFound, 404).show('nope')).rejects.toMatchObject({
            reason: 'item_not_found',
            notFound: true,
        })
    })

    /**
     * ⚠️ A VALIDATION FAILURE HAS NO `reason`, AND THAT IS THE SERVER'S DOING — the body comes
     * from the framework. A client inventing one here would circulate a key nothing sends and no
     * translation covers; a client requiring one would crash on the most ordinary error there is.
     */
    it('reads a framework validation failure as one, without inventing a reason', async () => {
        const failure = await clientServing(invalid, 422)
            .createFolder('')
            .catch((error: unknown) => error)

        expect(failure).toBeInstanceOf(MediaHubError)

        const error = failure as MediaHubError

        expect(error.reason).toBeNull()
        expect(error.invalid).toBe(true)
        expect(error.validation?.name?.[0]).toBe('The name field is required.')
    })
})
