import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import { fakeClient, folder, media } from './fake.test-utils'
import { useFolders } from './useFolders'
import { useMediaActions } from './useMediaActions'
import { useQuota } from './useQuota'

describe('acting on a media', () => {
    it('renames without touching the folder', async () => {
        const api = fakeClient()

        await useMediaActions(api).rename(media('m1'), 'Balance')

        expect(api.calls[0]?.args[1]).toEqual({ name: 'Balance' })
    })

    /**
     * ⚠️ MOVING TO THE ROOT SENDS `null`, IT DOES NOT OMIT THE KEY. Omitting it means "do not
     * move": the call would answer with a perfectly valid media and change nothing, and no error
     * anywhere would say the move was dropped.
     */
    it('sends null to move to the root', async () => {
        const api = fakeClient()

        await useMediaActions(api).move(media('m1'), null)

        expect(api.calls[0]?.args[1]).toEqual({ folder: null })
    })

    it('takes a folder or its id', async () => {
        const api = fakeClient()
        const actions = useMediaActions(api)

        await actions.move('m1', folder('f1'))
        await actions.move('m1', 'f2')

        expect(api.calls[0]?.args[1]).toEqual({ folder: 'f1' })
        expect(api.calls[1]?.args[1]).toEqual({ folder: 'f2' })
    })

    /** ⚠️ COPYING WITHOUT A TARGET IS NOT COPYING TO THE ROOT. */
    it('tells "copy here" apart from "copy to the root"', async () => {
        const api = fakeClient()
        const actions = useMediaActions(api)

        await actions.copy('m1')
        await actions.copy('m1', null)

        expect(api.calls[0]?.args[1]).toBeUndefined()
        expect(api.calls[1]?.args[1]).toBeNull()
    })

    it('annotates through the properties key', async () => {
        const api = fakeClient()

        await useMediaActions(api).annotate('m1', { alt: 'A report' })

        expect(api.calls[0]?.args[1]).toEqual({ properties: { alt: 'A report' } })
    })

    it('passes a batch through untouched', async () => {
        const api = fakeClient()
        const actions = useMediaActions(api)

        await actions.trash({ media: ['m1'] })
        await actions.restore({ media: ['m1'] })
        await actions.purge({ folders: ['f1'] })
        await actions.emptyTrash()

        expect(api.calls.map((call) => call.method)).toEqual([
            'trash',
            'restore',
            'purge',
            'emptyTrash',
        ])
    })
})

describe('when an action is refused', () => {
    /**
     * ⚠️ THE REFUSAL IS BOTH THROWN AND KEPT. A screen wants to render the last one without
     * wrapping every call; a caller chaining operations needs it to stop the chain. Offering only
     * one of the two makes half the callers write the other themselves.
     */
    it('throws and keeps it', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(403, null, 'No.'))

        const actions = useMediaActions(api)

        await expect(actions.rename('m1', 'x')).rejects.toBeInstanceOf(MediaHubError)
        expect(actions.error.value?.status).toBe(403)
        expect(actions.running.value).toBe(false)
    })

    it('wraps something that is not a refusal', async () => {
        const api = fakeClient()
        api.failWith(new Error('offline') as unknown as MediaHubError)

        const actions = useMediaActions(api)

        await actions.rename('m1', 'x').catch(() => undefined)

        expect(actions.error.value?.status).toBe(0)
    })

    it('clears the refusal on the next call', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(403, null, 'No.'))

        const actions = useMediaActions(api)
        await actions.rename('m1', 'x').catch(() => undefined)

        api.failWith(null)
        await actions.rename('m1', 'y')

        expect(actions.error.value).toBeNull()
    })
})

describe('folders', () => {
    /**
     * ⚠️ A RENAME MUST CARRY NO `parent`. The server tells a rename from a move by the presence
     * of the key: sending `parent: undefined` from a form object lifts the whole branch to the
     * root, which is a disappearance rather than a field error.
     */
    it('renames without a parent in the payload', async () => {
        const api = fakeClient()

        await useFolders(api).rename(folder('f1'), 'Accounts')

        expect(api.calls[0]?.args[1]).toEqual({ name: 'Accounts' })
        expect(Object.keys(api.calls[0]?.args[1] as object)).not.toContain('parent')
    })

    it('moves with a parent, root included', async () => {
        const api = fakeClient()
        const folders = useFolders(api)

        await folders.move('f1', 'f2')
        await folders.move('f1', null)

        expect(api.calls[0]?.args[1]).toEqual({ parent: 'f2' })
        expect(api.calls[1]?.args[1]).toEqual({ parent: null })
    })

    it('tells "create at the root" apart from "create wherever"', async () => {
        const api = fakeClient()
        const folders = useFolders(api)

        await folders.create('Invoices')
        await folders.create('Invoices', null)

        expect(api.calls[0]?.args[1]).toBeUndefined()
        expect(api.calls[1]?.args[1]).toBeNull()
    })

    it('keeps a refusal too', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(422, 'folder_cycle', 'No.'))

        const folders = useFolders(api)
        await folders.move('f1', 'f2').catch(() => undefined)

        expect(folders.error.value?.reason).toBe('folder_cycle')
    })
})

describe('the quota', () => {
    it('reads what is left', async () => {
        const api = fakeClient()
        api.answerQuota({ limit: 1000, used: 250, remaining: 750, unlimited: false })

        const quota = useQuota(api)
        await quota.refresh()

        expect(quota.quota.value?.remaining).toBe(750)
        expect(quota.ratio.value).toBe(0.25)
    })

    /**
     * ⚠️ AN UNLIMITED QUOTA HAS NO PERCENTAGE, and zero would be worse than nothing: a gauge at
     * 0 % reads as "empty" and at 100 % as "full", while the truth is that the question does not
     * apply.
     */
    it('has no ratio when there is no limit', async () => {
        const api = fakeClient()

        const quota = useQuota(api)
        await quota.refresh()

        expect(quota.ratio.value).toBeNull()
    })

    it('has no ratio before anything was read', () => {
        expect(useQuota(fakeClient()).ratio.value).toBeNull()
    })

    /** ⚠️ AND IT NEVER EXCEEDS ONE — a gauge past the end of its track reads as a rendering bug. */
    it('caps the ratio at one when usage passed the limit', async () => {
        const api = fakeClient()
        api.answerQuota({ limit: 100, used: 250, remaining: 0, unlimited: false })

        const quota = useQuota(api)
        await quota.refresh()

        expect(quota.ratio.value).toBe(1)
    })

    it('keeps a refusal rather than throwing at a gauge', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(500, null, 'Boom.'))

        const quota = useQuota(api)
        await quota.refresh()

        expect(quota.error.value?.status).toBe(500)
        expect(quota.loading.value).toBe(false)
    })
})
