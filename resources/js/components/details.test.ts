import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { fakeClient, media } from '../vue/fake.test-utils'
import MhDetailsPanel from './MhDetailsPanel.vue'

function panel(props: Record<string, unknown> = {}) {
    const api = fakeClient()
    const wrapper = mount(MhDetailsPanel, {
        props: { media: media('m1', { name: 'Report', size: 2048 }), client: api, ...props },
    })

    return { wrapper, api }
}

describe('the details of one file', () => {
    it('shows nothing when nothing is selected', () => {
        expect(panel({ media: null }).wrapper.find('aside').exists()).toBe(false)
    })

    it('spells the size out rather than showing bytes', () => {
        expect(panel().wrapper.text()).toContain('2 kB')
    })

    /**
     * ⚠️ THE ALTERNATIVE TEXT IS A FIELD, NOT AN AFTERTHOUGHT. This is the only place in a media
     * library where somebody can write it, and a library that never asks produces a site where
     * every picture is silent.
     */
    it('offers the alternative text beside the name, both labelled', () => {
        const { wrapper } = panel()
        const labels = wrapper.findAll('label').map((label) => label.text())

        expect(labels).toContain('Alternative text')

        for (const label of wrapper.findAll('label')) {
            expect(wrapper.find(`#${label.attributes('for')}`).exists()).toBe(true)
        }
    })

    it('starts from what the file already says', () => {
        const { wrapper } = panel({
            media: media('m1', { name: 'Report', custom_properties: { alt: 'A bar chart' } }),
        })

        const values = wrapper.findAll('input').map((input) => (input.element as HTMLInputElement).value)

        expect(values).toEqual(['Report', 'A bar chart'])
    })

    /**
     * ⚠️ THE FIELDS FOLLOW THE FILE. Clicking from one picture to the next while a name is
     * half-typed would otherwise carry that text onto the second one — and the first save would
     * rename the wrong file.
     */
    it('resets when another file is shown', async () => {
        const { wrapper } = panel()

        await wrapper.findAll('input')[0]?.setValue('Half-typed')
        await wrapper.setProps({ media: media('m2', { name: 'Invoice' }) })

        expect((wrapper.findAll('input')[0]?.element as HTMLInputElement).value).toBe('Invoice')
    })

    /** ⚠️ NOTHING TO SAVE MEANS NOTHING TO PRESS — otherwise a click rewrites a record with itself. */
    it('cannot be saved while nothing changed', async () => {
        const { wrapper } = panel()

        expect(wrapper.find('button').attributes('disabled')).toBeDefined()

        await wrapper.findAll('input')[0]?.setValue('Renamed')

        expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
    })

    it('renames when the name changed', async () => {
        const { wrapper, api } = panel()

        await wrapper.findAll('input')[0]?.setValue('Renamed')
        await wrapper.find('button').trigger('click')
        await nextTick()

        expect(api.calls[0]?.method).toBe('update')
        expect(api.calls[0]?.args[1]).toEqual({ name: 'Renamed' })
    })

    /**
     * ⚠️ ONLY WHAT CHANGED IS SENT. Attaching the properties to every rename would overwrite an
     * alternative text somebody else edited in the meantime, and the loss would be invisible:
     * nothing failed, the field simply went back to what this screen happened to be holding.
     */
    it('sends nothing about the text when only the name changed', async () => {
        const { wrapper, api } = panel()

        await wrapper.findAll('input')[0]?.setValue('Renamed')
        await wrapper.find('button').trigger('click')
        await nextTick()

        expect(api.calls).toHaveLength(1)
    })

    it('writes the alternative text when that is what changed', async () => {
        const { wrapper, api } = panel()

        await wrapper.findAll('input')[1]?.setValue('A bar chart')
        await wrapper.find('button').trigger('click')
        await nextTick()

        expect(api.calls).toHaveLength(1)
        expect(api.calls[0]?.args[1]).toMatchObject({ properties: { alt: 'A bar chart' } })
    })

    it('reports what it saved', async () => {
        const { wrapper } = panel()

        await wrapper.findAll('input')[0]?.setValue('Renamed')
        await wrapper.find('button').trigger('click')
        await nextTick()

        expect(wrapper.emitted('updated')).toHaveLength(1)
    })
})
