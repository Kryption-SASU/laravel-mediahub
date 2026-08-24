import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { fakeClient, media } from '../vue/fake.test-utils'
import MhMediaPicker from './MhMediaPicker.vue'

type Picker = { pick: (options?: Record<string, unknown>) => Promise<unknown[]>; cancel: () => void }

async function picker(props: Record<string, unknown> = {}) {
    const api = fakeClient()
    api.answerBrowse({ media: [media('m1'), media('m2')] })

    const wrapper = mount(MhMediaPicker, { props: { client: api, ...props }, attachTo: document.body })

    return { wrapper, api, picker: wrapper.vm as unknown as Picker }
}

async function settle(): Promise<void> {
    for (let turn = 0; turn < 6; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

describe('the picker as a promise', () => {
    /**
     * ⚠️ THE LISTING IS FETCHED WHEN IT OPENS, NOT WHEN IT MOUNTS. A picker sits beside a form on
     * every page that carries one: loading a page of media at mount would mean a request per
     * screen for a dialog most people never open.
     */
    it('asks for nothing until it is opened', async () => {
        const { api } = await picker()

        expect(api.calls).toHaveLength(0)
    })

    it('loads when it opens', async () => {
        const { api, picker: instance } = await picker()

        void instance.pick()
        await settle()

        expect(api.calls.some((call) => call.method === 'browse')).toBe(true)
    })

    it('resolves with what was chosen', async () => {
        const { wrapper, picker: instance } = await picker()

        const chosen = instance.pick()
        await settle()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await wrapper.findAll('button')[1]?.trigger('click')

        await expect(chosen).resolves.toHaveLength(1)
    })

    /**
     * ⚠️ A DISMISSAL RESOLVES WITH NOTHING; it does not reject. Closing a picker is the most
     * ordinary thing anyone does with one, and rejecting would hand an unhandled rejection to
     * whoever forgot a `try` around a click on "cancel".
     */
    it('resolves empty when it is dismissed', async () => {
        const { wrapper, picker: instance } = await picker()

        const chosen = instance.pick()
        await settle()

        await wrapper.findAll('button')[0]?.trigger('click')

        await expect(chosen).resolves.toEqual([])
    })

    it('reads Escape as a dismissal', async () => {
        const { wrapper, picker: instance } = await picker()

        const chosen = instance.pick()
        await settle()

        await wrapper.find('dialog').trigger('cancel')

        await expect(chosen).resolves.toEqual([])
    })

    /**
     * ⚠️ CONFIRMING WITH NOTHING CHOSEN MUST BE IMPOSSIBLE. Answering with an empty list on a
     * deliberate confirmation is, to the caller, indistinguishable from a dismissal — and the
     * caller then clears a field the person meant to keep.
     */
    it('refuses to confirm an empty choice', async () => {
        const { wrapper, picker: instance } = await picker()

        void instance.pick()
        await settle()

        expect(wrapper.findAll('button')[1]?.attributes('disabled')).toBeDefined()
    })

    it('opens an item straight into the answer', async () => {
        const { wrapper, picker: instance } = await picker()

        const chosen = instance.pick()
        await settle()

        await wrapper.findAll('[role="option"]')[1]?.trigger('dblclick')

        await expect(chosen).resolves.toHaveLength(1)
    })

    it('lets several through when asked', async () => {
        const { wrapper, picker: instance } = await picker()

        const chosen = instance.pick({ multiple: true })
        await settle()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await wrapper.findAll('[role="option"]')[1]?.trigger('click')
        await wrapper.findAll('button')[1]?.trigger('click')

        await expect(chosen).resolves.toHaveLength(2)
    })

    /**
     * ⚠️ THE RESTRICTION IS ENFORCED HERE AS WELL AS ASKED FOR ON THE SERVER. A picker opened for
     * images that hands back a spreadsheet is worse than one that never filtered — the caller
     * writes it into a field that will only ever be rendered in an `<img>`.
     */
    it('shows nothing of a kind it was not opened for', async () => {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1', { type: 'document' }), media('m2', { type: 'image' })] })

        const wrapper = mount(MhMediaPicker, { props: { client: api }, attachTo: document.body })
        const instance = wrapper.vm as unknown as Picker

        void instance.pick({ types: ['image'] })
        await settle()

        expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    })

    /** ⚠️ AND IT FORGETS THE PREVIOUS ROUND — a picker reopening on last week's ticks is a trap. */
    it('starts empty every time it opens', async () => {
        const { wrapper, picker: instance } = await picker()

        const first = instance.pick()
        await settle()
        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await wrapper.findAll('button')[0]?.trigger('click')
        await first

        void instance.pick()
        await settle()

        expect(wrapper.findAll('[aria-selected="true"]')).toHaveLength(0)
    })

    it('searches on submit', async () => {
        const { wrapper, api, picker: instance } = await picker()

        void instance.pick()
        await settle()

        await wrapper.find('input[type="search"]').setValue('invoice')
        await wrapper.find('form').trigger('submit')
        await settle()

        const searched = api.calls.filter(
            (call) => (call.args[0] as { search?: unknown } | undefined)?.search === 'invoice',
        )

        expect(searched).toHaveLength(1)
    })
})
