import { describe, expect, it } from 'vitest'
import { folder, media } from './fake.test-utils'
import { useSelection } from './useSelection'

describe('what is selected', () => {
    it('toggles a thing on and off', () => {
        const selection = useSelection()

        selection.toggle('media', 'm1')
        expect(selection.isSelected('media', 'm1')).toBe(true)

        selection.toggle('media', 'm1')
        expect(selection.isSelected('media', 'm1')).toBe(false)
    })

    /** ⚠️ SELECTING TWICE MUST NOT SELECT TWICE — a batch would then name the same item twice. */
    it('does not hold the same thing twice', () => {
        const selection = useSelection()

        selection.select('media', 'm1')
        selection.select('media', 'm1')

        expect(selection.media.value).toEqual(['m1'])
    })

    /**
     * ⚠️ A MEDIA AND A FOLDER MAY SHARE AN IDENTIFIER, and on an adopted schema they routinely do
     * — both are integers from their own table. One list with a kind flag would make selecting
     * folder 7 light up media 7.
     */
    it('keeps media and folders apart even under the same identifier', () => {
        const selection = useSelection()

        selection.select('media', '7')

        expect(selection.isSelected('folder', '7')).toBe(false)
        expect(selection.count.value).toBe(1)
    })

    it('counts both kinds together', () => {
        const selection = useSelection()

        selection.select('media', 'm1')
        selection.select('folder', 'f1')

        expect(selection.count.value).toBe(2)
        expect(selection.empty.value).toBe(false)
    })

    it('replaces everything with what it is handed', () => {
        const selection = useSelection()

        selection.select('media', 'old')
        selection.replace({ media: [media('m1'), media('m2')], folders: [folder('f1')] })

        expect(selection.media.value).toEqual(['m1', 'm2'])
        expect(selection.folders.value).toEqual(['f1'])
    })

    it('empties on demand', () => {
        const selection = useSelection()

        selection.select('media', 'm1')
        selection.clear()

        expect(selection.empty.value).toBe(true)
    })
})

describe('handing it to the server', () => {
    /**
     * ⚠️ AN EMPTY LIST IS OMITTED, NOT SENT EMPTY. The server refuses a batch that names nothing,
     * and it is right to — but a payload carrying `folders: []` reads as a deliberate empty
     * selection rather than as "no folders here", and the refusal then points at the wrong thing.
     */
    it('omits the list that has nothing in it', () => {
        const selection = useSelection()

        selection.select('media', 'm1')

        expect(selection.asSelection()).toEqual({ media: ['m1'] })
    })

    it('sends both when both have something', () => {
        const selection = useSelection()

        selection.select('media', 'm1')
        selection.select('folder', 'f1')

        expect(selection.asSelection()).toEqual({ media: ['m1'], folders: ['f1'] })
    })

    it('sends nothing at all when nothing is selected', () => {
        expect(useSelection().asSelection()).toEqual({})
    })

    /** ⚠️ A COPY, NOT THE LIVE ARRAY: a caller must not be able to grow the selection by accident. */
    it('hands out a copy', () => {
        const selection = useSelection()
        selection.select('media', 'm1')

        const payload = selection.asSelection()
        ;(payload.media as string[]).push('sneaky')

        expect(selection.media.value).toEqual(['m1'])
    })
})
