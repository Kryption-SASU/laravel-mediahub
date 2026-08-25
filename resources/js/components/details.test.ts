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

/**
 * ⚠️ FIELDS ARE FOUND BY THEIR LABEL, NOT BY THEIR POSITION. The panel gained an address field
 * above these two, and every `findAll('input')[0]` quietly started editing that one instead —
 * each test then failed on an assertion three lines later, which is a slow and misleading way to
 * learn that a selector moved. The same goes for `find('button')`: the copy button now comes
 * first in the markup.
 */
function field(wrapper: ReturnType<typeof mount>, label: string) {
    const tag = wrapper.findAll('label').find((candidate) => candidate.text() === label)

    return wrapper.find('#' + String(tag?.attributes('for')))
}

function save(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('button').filter((button) => button.text() === 'Save')[0]
}

describe('the details of one file', () => {
    /**
     * ⚠️ THE RESTING PANEL IS RENDERED, AND THAT IS THE POINT OF IT. Omitted, the column appears
     * on the first click and shoves the grid sideways under the pointer that caused it — and
     * until that click nothing on screen suggests that choosing a file shows anything at all.
     */
    it('holds its column open with nothing selected', () => {
        const wrapper = panel({ media: null }).wrapper

        expect(wrapper.find('aside').exists()).toBe(true)
        expect(wrapper.text()).toContain('No selection')
    })

    /** ⚠️ AND IT IS NOT THE FORM. Fields for a file nobody chose would save onto nothing. */
    it('offers nothing to edit while nothing is selected', () => {
        expect(panel({ media: null }).wrapper.find('input').exists()).toBe(false)
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

        expect((field(wrapper, 'Name').element as HTMLInputElement).value).toBe('Report')
        expect((field(wrapper, 'Alternative text').element as HTMLInputElement).value).toBe(
            'A bar chart',
        )
    })

    /**
     * ⚠️ THE FIELDS FOLLOW THE FILE. Clicking from one picture to the next while a name is
     * half-typed would otherwise carry that text onto the second one — and the first save would
     * rename the wrong file.
     */
    it('resets when another file is shown', async () => {
        const { wrapper } = panel()

        await field(wrapper, 'Name').setValue('Half-typed')
        await wrapper.setProps({ media: media('m2', { name: 'Invoice' }) })

        expect((field(wrapper, 'Name').element as HTMLInputElement).value).toBe('Invoice')
    })

    /** ⚠️ NOTHING TO SAVE MEANS NOTHING TO PRESS — otherwise a click rewrites a record with itself. */
    it('cannot be saved while nothing changed', async () => {
        const { wrapper } = panel()

        expect(save(wrapper)?.attributes('disabled')).toBeDefined()

        await field(wrapper, 'Name').setValue('Renamed')

        expect(save(wrapper)?.attributes('disabled')).toBeUndefined()
    })

    it('renames when the name changed', async () => {
        const { wrapper, api } = panel()

        await field(wrapper, 'Name').setValue('Renamed')
        await save(wrapper)?.trigger('click')
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

        await field(wrapper, 'Name').setValue('Renamed')
        await save(wrapper)?.trigger('click')
        await nextTick()

        expect(api.calls).toHaveLength(1)
    })

    it('writes the alternative text when that is what changed', async () => {
        const { wrapper, api } = panel()

        await field(wrapper, 'Alternative text').setValue('A bar chart')
        await save(wrapper)?.trigger('click')
        await nextTick()

        expect(api.calls).toHaveLength(1)
        expect(api.calls[0]?.args[1]).toMatchObject({ properties: { alt: 'A bar chart' } })
    })

    it('reports what it saved', async () => {
        const { wrapper } = panel()

        await field(wrapper, 'Name').setValue('Renamed')
        await save(wrapper)?.trigger('click')
        await nextTick()

        expect(wrapper.emitted('updated')).toHaveLength(1)
    })
})

/**
 * ⚠️ FOUND BY WHAT IT ANNOUNCES, since it no longer says anything on screen. The wording moved to
 * the label when the control became a drawing — which is also what tells somebody the copy
 * actually happened.
 */
function copier(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAll('button')
        .filter((button) => ['Copy', 'Copied'].includes(button.attributes('aria-label') ?? ''))[0]
}

describe('what the panel says about a file', () => {
    /**
     * ⚠️ THE ADDRESS IS A FIELD, NOT A LINE OF TEXT. A `<span>` cannot be selected reliably, and
     * the clipboard API does not exist outside a secure context — which is to say on every
     * `http://` development host there is. Read-only and selectable, there is always a way to
     * take it.
     */
    it('shows the address as something you can select', () => {
        const url = field(panel().wrapper, 'Full url')

        expect(url.attributes('readonly')).toBeDefined()
        expect((url.element as HTMLInputElement).value).toBe('/media/m1/file')
    })

    it('copies the address, and says it did', async () => {
        const written: string[] = []

        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: (text: string) => { written.push(text); return Promise.resolve() } },
            configurable: true,
        })

        const { wrapper } = panel()

        await copier(wrapper)?.trigger('click')
        await nextTick()

        expect(written).toEqual(['/media/m1/file'])

        /* ⚠️ THE CONFIRMATION IS IN THE LABEL, because the control is a drawing. An icon button
         * that says nothing is one a screen reader announces as "button". */
        expect(copier(wrapper)?.attributes('aria-label')).toBe('Copied')
    })

    /**
     * ⚠️ AND IT ONLY SAYS SO WHEN IT REALLY HAPPENED. Announcing "Copied" over a clipboard that
     * refused — no secure context, no permission, the document not focused — sends somebody off
     * to paste nothing, and the failure surfaces somewhere else entirely.
     */
    it('does not claim to have copied when nothing did', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })
        Object.defineProperty(document, 'execCommand', { value: () => false, configurable: true })

        const { wrapper } = panel()

        await copier(wrapper)?.trigger('click')
        await nextTick()

        expect(copier(wrapper)?.attributes('aria-label')).toBe('Copy')
    })

    it('gives the moments the file carries', () => {
        const { wrapper } = panel({
            media: media('m1', {
                created_at: '2026-08-12T09:30:00+00:00',
                updated_at: '2026-08-13T09:30:00+00:00',
            }),
        })

        expect(wrapper.text()).toContain('Uploaded at')
        expect(wrapper.text()).toContain('Modified at')
        expect(wrapper.text()).toContain('2026')
    })

    /** ⚠️ A MOMENT THE SERVER DID NOT GIVE IS ABSENT, not a term with nothing under it. */
    it('says nothing about a moment it was not given', () => {
        const { wrapper } = panel({ media: media('m1', { created_at: null, updated_at: null }) })

        expect(wrapper.text()).not.toContain('Uploaded at')
    })

    /** ⚠️ NOR DOES IT PRINT "Invalid Date" — which reads as a fact we lost rather than never had. */
    it('says nothing about a moment it cannot read', () => {
        const { wrapper } = panel({ media: media('m1', { created_at: 'not a date' }) })

        expect(wrapper.text()).not.toContain('Invalid')
    })
})

describe('the panel as a way of choosing', () => {
    /**
     * ⚠️ NO BUTTON WHERE NOBODY IS WAITING. A library opened from a menu has no caller: an
     * "use this file" button there hands the file to nobody, and the click does nothing at all.
     * The library being replaced applies the same rule — its own insert button stays hidden
     * unless the panel was opened from a field.
     */
    it('offers nothing to use when nobody asked for a file', () => {
        expect(panel().wrapper.text()).not.toContain('Use this file')
    })

    it('hands the file over when somebody did', async () => {
        const { wrapper } = panel({ selectable: true })

        await wrapper.findAll('button').filter((b) => b.text() === 'Use this file')[0]?.trigger('click')

        expect(wrapper.emitted('use')?.[0]?.[0]).toMatchObject({ id: 'm1' })
    })
})
