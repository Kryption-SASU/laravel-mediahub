import { describe, expect, it } from 'vitest'
import { media } from './fake.test-utils'
import { useMediaPicker } from './useMediaPicker'

describe('picking a media', () => {
    it('resolves with what was chosen', async () => {
        const picker = useMediaPicker()

        const chosen = picker.pick()
        picker.choose(media('m1'))

        await expect(chosen).resolves.toHaveLength(1)
        expect(picker.open.value).toBe(false)
    })

    /**
     * ⚠️ A DISMISSAL RESOLVES, IT DOES NOT REJECT. Closing a picker is the most ordinary thing a
     * person does with one; rejecting would force every caller to wrap `await pick()` in a `try`
     * to handle it, and the one who forgets gets an unhandled rejection in the console for a
     * click on "cancel".
     */
    it('resolves with nothing when it is dismissed', async () => {
        const picker = useMediaPicker()

        const chosen = picker.pick()
        picker.cancel()

        await expect(chosen).resolves.toEqual([])
    })

    it('carries what was asked for while it is open', () => {
        const picker = useMediaPicker()

        void picker.pick({ multiple: true, types: ['image'], title: 'Pick a cover' })

        expect(picker.open.value).toBe(true)
        expect(picker.multiple.value).toBe(true)
        expect(picker.request.value?.types).toEqual(['image'])
        expect(picker.request.value?.title).toBe('Pick a cover')
    })

    /** ⚠️ A SINGLE PICKER MUST NOT HAND BACK TWO, whatever the screen sends it. */
    it('keeps only the first when it is not multiple', async () => {
        const picker = useMediaPicker()

        const chosen = picker.pick()
        picker.choose([media('m1'), media('m2')])

        await expect(chosen).resolves.toEqual([expect.objectContaining({ id: 'm1' })])
    })

    it('keeps them all when it is', async () => {
        const picker = useMediaPicker()

        const chosen = picker.pick({ multiple: true })
        picker.choose([media('m1'), media('m2')])

        await expect(chosen).resolves.toHaveLength(2)
    })

    /**
     * ⚠️ A PROMISE THAT NEVER SETTLES IS A SCREEN THAT WAITS FOREVER, and the caller has no way
     * to find out. Opening a second picker over a first has to release the first — empty-handed,
     * because nothing was chosen.
     */
    it('releases a previous request when a new one opens', async () => {
        const picker = useMediaPicker()

        const first = picker.pick()
        const second = picker.pick({ multiple: true })

        picker.choose([media('m1')])

        await expect(first).resolves.toEqual([])
        await expect(second).resolves.toHaveLength(1)
    })

    it('does nothing when nothing is waiting', () => {
        const picker = useMediaPicker()

        expect(() => picker.cancel()).not.toThrow()
        expect(picker.open.value).toBe(false)
    })
})
