import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { fakeClient, media } from '../vue/fake.test-utils'
import MhMediaGallery from './MhMediaGallery.vue'
import MhMediaInput from './MhMediaInput.vue'

async function settle(): Promise<void> {
    for (let turn = 0; turn < 6; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

function client() {
    const api = fakeClient()
    api.answerBrowse({ media: [media('m1'), media('m2')] })

    return api
}

/**
 * The button that opens the picker, found by its wording.
 *
 * ⚠️ NOT BY POSITION. The rendered buttons include the picker's own cancel and confirm,
 * so an index counted from either end lands on a different button as soon as the list grows or
 * the dialog opens — and the test then reports on something nobody meant to press.
 */
function opener(wrapper: ReturnType<typeof mount>, label: string) {
    return wrapper.findAll('button').find((button) => button.text() === label)
}

/** Clicks through the picker the field opened: choose the nth option, then confirm. */
function confirm(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('dialog button').filter((button) => button.text() === 'Choose')[0]
}

async function pickThrough(wrapper: ReturnType<typeof mount>, positions: number[]): Promise<void> {
    await settle()

    for (const position of positions) {
        await wrapper.findAll('[role="option"]')[position]?.trigger('click')
    }

    /* ⚠️ THE CONFIRM BUTTON BY ITS WORDING, NOT BY ITS POSITION. Every tile in the picker
     * now carries a menu button of its own, so an index into `findAll('button')` started
     * clicking one of those instead — and the test failed three assertions later, on the
     * choice never being reported. */
    await confirm(wrapper)?.trigger('click')
    await settle()
}

describe('one media in a form', () => {
    /**
     * ⚠️ A HIDDEN FIELD IS WHAT MAKES A PLAIN BLADE FORM WORK. Without it the host has to wire a
     * change handler into their own state — a line everybody has to write, and nobody remembers
     * to write twice.
     */
    it('posts the identifier through a hidden field', () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', name: 'avatar_id', client: client() },
        })

        expect(wrapper.find('input[type="hidden"]').attributes('value')).toBe('m1')
        expect(wrapper.find('input[type="hidden"]').attributes('name')).toBe('avatar_id')
    })

    /**
     * ⚠️ AND IT STAYS IN THE PAYLOAD WHEN IT IS EMPTY. A field that disappears once cleared
     * leaves the server unable to tell "unset it" from "this form never carried it".
     */
    it('keeps the field when nothing is chosen', () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: null, name: 'avatar_id', client: client() },
        })

        expect(wrapper.find('input[type="hidden"]').attributes('value')).toBe('')
    })

    it('shows what the host already had, without fetching it', () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1', { name: 'The cover' }), client: client() },
        })

        expect(wrapper.text()).toContain('The cover')
    })

    it('reports the choice as an identifier and as an object', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: null, client: client() },
            attachTo: document.body,
        })

        await wrapper.find('button').trigger('click')
        await pickThrough(wrapper, [0])

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['m1'])
        expect(wrapper.emitted('update:media')?.[0]?.[0]).toMatchObject({ id: 'm1' })
    })

    /**
     * ⚠️ A DISMISSAL CHANGES NOTHING. Reading "nothing came back" as "clear it" erases a chosen
     * file every time somebody opens the picker to look and thinks better of it.
     */
    it('keeps what was there when the picker is dismissed', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1'), client: client() },
            attachTo: document.body,
        })

        await wrapper.find('button').trigger('click')
        await settle()

        await wrapper.findAll('dialog button')[0]?.trigger('click')
        await settle()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('clears on demand', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1'), client: client() },
        })

        await wrapper.findAll('button')[1]?.trigger('click')

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
    })

    /**
     * ⚠️ THE PREVIEW FOLLOWS THE MODEL. A host clearing the value programmatically, or loading
     * another record into the same form, would otherwise leave the previous picture on screen
     * beside an empty field — and the person submits believing they kept it.
     */
    it('drops the preview when the model is emptied from outside', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1', { name: 'The cover' }), client: client() },
        })

        await wrapper.setProps({ modelValue: null })

        expect(wrapper.text()).not.toContain('The cover')
    })

    /**
     * ⚠️ A HOST THAT LOADS THE MEDIA AFTER THE FORM must still get a preview. Fetching the
     * record and its picture separately is ordinary, and a field that only reads the object at
     * mount would sit empty beside a value it already has.
     */
    it('shows the media when it arrives after the identifier', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: null, client: client() },
        })

        await wrapper.setProps({ media: media('m1', { name: 'The cover' }) })

        expect(wrapper.text()).toContain('The cover')
    })

    /** ⚠️ AND A MEDIA THAT DOES NOT MATCH THE MODEL IS IGNORED, not shown beside the wrong value. */
    it('ignores a media that is not the one selected', async () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1', { name: 'Right' }), client: client() },
        })

        await wrapper.setProps({ media: media('m2', { name: 'Wrong' }) })

        expect(wrapper.text()).not.toContain('Wrong')
    })

    it('refuses every action while disabled', () => {
        const wrapper = mount(MhMediaInput, {
            props: { modelValue: 'm1', media: media('m1'), disabled: true, client: client() },
        })

        // Its own buttons, not the picker's: the dialog it carries has buttons of its own.
        for (const label of ['Replace', 'Remove']) {
            expect(opener(wrapper, label)?.attributes('disabled')).toBeDefined()
        }
    })
})

describe('several media in a form, in an order', () => {
    it('posts one field per item, in order', () => {
        const wrapper = mount(MhMediaGallery, {
            props: {
                modelValue: ['m2', 'm1'],
                name: 'gallery_ids[]',
                media: [media('m1'), media('m2')],
                client: client(),
            },
        })

        const values = wrapper.findAll('input[type="hidden"]').map((input) => input.attributes('value'))

        expect(values).toEqual(['m2', 'm1'])
    })

    /** ⚠️ THE ORDER IS THE VALUE — a gallery that reshuffles itself makes somebody redo the work. */
    it('moves an item and reports the new order', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1', 'm2'], media: [media('m1'), media('m2')], client: client() },
        })

        await wrapper.findAll('li')[1]?.findAll('button')[0]?.trigger('click')

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['m2', 'm1']])
    })

    /** ⚠️ AND A MOVE OFF THE END IS REFUSED RATHER THAN WRAPPED AROUND. */
    it('cannot move the first item earlier', () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1', 'm2'], media: [media('m1'), media('m2')], client: client() },
        })

        expect(wrapper.findAll('li')[0]?.findAll('button')[0]?.attributes('disabled')).toBeDefined()
    })

    /**
     * ⚠️ SIX BUTTONS ALL ANNOUNCED AS "MOVE EARLIER" tell a screen reader user nothing about
     * which picture they are about to move.
     */
    it('names the item each button acts on', () => {
        const wrapper = mount(MhMediaGallery, {
            props: {
                modelValue: ['m1'],
                media: [media('m1', { name: 'Beach' })],
                client: client(),
            },
        })

        expect(wrapper.findAll('li')[0]?.findAll('button')[2]?.attributes('aria-label')).toBe('Remove: Beach')
    })

    it('removes an item', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1', 'm2'], media: [media('m1'), media('m2')], client: client() },
        })

        await wrapper.findAll('li')[0]?.findAll('button')[2]?.trigger('click')

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['m2']])
    })

    it('adds what was chosen, keeping what was there', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m2'], media: [media('m2')], client: client() },
            attachTo: document.body,
        })

        await opener(wrapper, 'Add files')?.trigger('click')
        await pickThrough(wrapper, [0])

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['m2', 'm1']])
    })

    /** ⚠️ THE SAME FILE TWICE IS A MISTAKE, NOT A FEATURE — and the duplicate survives every save. */
    it('refuses to add the same file twice', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1'], media: [media('m1')], client: client() },
            attachTo: document.body,
        })

        await opener(wrapper, 'Add files')?.trigger('click')
        await pickThrough(wrapper, [0])

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['m1']])
    })

    it('stops adding at the maximum', () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1'], media: [media('m1')], max: 1, client: client() },
        })

        expect(opener(wrapper, 'Add files')?.attributes('disabled')).toBeDefined()
    })

    /**
     * ⚠️ A MEDIA IS REMEMBERED AFTER IT LEAVES THE LIST. Someone who removes a picture and puts
     * it back would otherwise get a blank tile: the object came from a picker page that has since
     * been replaced, and nothing would fetch it again.
     */
    it('still knows an item that was taken out and put back', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: {
                modelValue: ['m1'],
                media: [media('m1', { name: 'Beach' })],
                client: client(),
            },
        })

        await wrapper.setProps({ modelValue: [] })
        await wrapper.setProps({ modelValue: ['m1'] })

        expect(wrapper.text()).toContain('Beach')
    })

    /** ⚠️ A DISMISSAL ADDS NOTHING, exactly as it changes nothing on the single field. */
    it('adds nothing when the picker is dismissed', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m2'], media: [media('m2')], client: client() },
            attachTo: document.body,
        })

        await opener(wrapper, 'Add files')?.trigger('click')
        await settle()
        await wrapper.findAll('dialog button')[0]?.trigger('click')
        await settle()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    /**
     * ⚠️ THE MAXIMUM CUTS THE ADDITION, it does not refuse it outright. Choosing four files
     * for three remaining places should keep three; refusing the lot means the person picks
     * again and guesses how many were allowed.
     */
    it('keeps only what fits under the maximum', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: [], media: [], max: 1, client: client() },
            attachTo: document.body,
        })

        await opener(wrapper, 'Add files')?.trigger('click')
        await settle()
        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await wrapper.findAll('[role="option"]')[1]?.trigger('click')
        await confirm(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['m1']])
    })

    it('reports the objects alongside the identifiers', async () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['m1', 'm2'], media: [media('m1'), media('m2')], client: client() },
        })

        await wrapper.findAll('li')[0]?.findAll('button')[2]?.trigger('click')

        expect(wrapper.emitted('update:media')?.[0]?.[0]).toMatchObject([{ id: 'm2' }])
    })

    /**
     * ⚠️ AN IDENTIFIER WITH NO OBJECT IS STILL SHOWN. A host that posts a saved list without
     * loading the media would otherwise render an empty row, and somebody would remove a file
     * they could not see in order to tidy up.
     */
    it('shows a row for an identifier it knows nothing about', () => {
        const wrapper = mount(MhMediaGallery, {
            props: { modelValue: ['unknown-id'], media: [], client: client() },
        })

        expect(wrapper.findAll('li')).toHaveLength(1)
        expect(wrapper.text()).toContain('unknown-id')
    })
})
