import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { folder } from '../vue/fake.test-utils'
import MhBreadcrumb from './MhBreadcrumb.vue'
import MhToolbar from './MhToolbar.vue'

const trail = [folder('f1', { name: 'Clients' }), folder('f2', { name: 'Acme' })]

describe('the breadcrumb', () => {
    it('always offers the way back to the root', () => {
        const wrapper = mount(MhBreadcrumb, { props: { trail: [] } })

        expect(wrapper.find('button').text()).toBe('All files')
    })

    /**
     * ⚠️ THE CURRENT FOLDER IS NOT A LINK. Rendering the last segment as one means a screen reader
     * announces a route to the page somebody is already on, and a click that appears to do
     * nothing at all.
     */
    it('leaves the folder being looked at as text', () => {
        const wrapper = mount(MhBreadcrumb, { props: { trail } })

        expect(wrapper.findAll('button')).toHaveLength(2)
        expect(wrapper.find('[aria-current="page"]').text()).toBe('Acme')
    })

    it('goes back to an ancestor', async () => {
        const wrapper = mount(MhBreadcrumb, { props: { trail } })

        await wrapper.findAll('button')[1]?.trigger('click')

        expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ id: 'f1' })
    })

    it('goes back to the root with nothing rather than with a folder', async () => {
        const wrapper = mount(MhBreadcrumb, { props: { trail } })

        await wrapper.findAll('button')[0]?.trigger('click')

        expect(wrapper.emitted('open')?.[0]).toEqual([null])
    })

    it('announces itself as navigation', () => {
        expect(mount(MhBreadcrumb, { props: { trail } }).find('nav').attributes('aria-label')).toBe(
            'Breadcrumb',
        )
    })
})

describe('the toolbar', () => {
    it('searches on submit rather than on every keystroke', async () => {
        const wrapper = mount(MhToolbar)

        await wrapper.find('input[type="search"]').setValue('invoice')

        expect(wrapper.emitted('search')).toBeUndefined()

        await wrapper.find('form').trigger('submit')

        expect(wrapper.emitted('search')?.[0]).toEqual(['invoice'])
    })

    /**
     * ⚠️ THE FIELD FOLLOWS THE QUERY. A host restoring a saved search, or clearing filters from
     * elsewhere, would otherwise leave this box showing a term nothing is filtered by.
     */
    it('follows a search set from outside', async () => {
        const wrapper = mount(MhToolbar)

        await wrapper.setProps({ search: 'from elsewhere' })

        expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe(
            'from elsewhere',
        )
    })

    /**
     * ⚠️ THE SORT OPTIONS COME FROM THE TYPE, WHICH MIRRORS THE SERVER'S ALLOW-LIST. A second
     * list typed into the template would drift the day a column is added, and offer an order the
     * server silently ignores — which reads as sorting being broken.
     */
    it('offers only orders the server accepts', () => {
        const wrapper = mount(MhToolbar)
        const values = wrapper
            .findAll('select')[1]
            ?.findAll('option')
            .map((option) => option.attributes('value'))

        expect(values).toEqual(['created_at', 'updated_at', 'name', 'size'])
    })

    it('reports a change of order, keeping the direction', async () => {
        const wrapper = mount(MhToolbar, { props: { direction: 'asc' } })

        await wrapper.findAll('select')[1]?.setValue('size')

        expect(wrapper.emitted('sort')?.[0]).toEqual(['size', 'asc'])
    })

    /** ⚠️ AND THE BUTTON SAYS WHAT IT WILL DO, not what is: an arrow alone is read out as nothing. */
    it('turns the direction round, and names the move', async () => {
        const wrapper = mount(MhToolbar, { props: { sort: 'name', direction: 'asc' } })
        const button = wrapper.find('button')

        expect(button.attributes('aria-label')).toBe('Sort descending')

        await button.trigger('click')

        expect(wrapper.emitted('sort')?.[0]).toEqual(['name', 'desc'])
    })

    it('filters by kind, and back to everything', async () => {
        const wrapper = mount(MhToolbar)
        const select = wrapper.findAll('select')[0]

        await select?.setValue('image')
        expect(wrapper.emitted('filter')?.[0]).toEqual([['image']])

        await select?.setValue('')
        expect(wrapper.emitted('filter')?.[1]).toEqual([[]])
    })

    it('labels every one of its controls', () => {
        const wrapper = mount(MhToolbar)

        for (const label of wrapper.findAll('label')) {
            expect(wrapper.find(`#${label.attributes('for')}`).exists()).toBe(true)
        }

        expect(wrapper.findAll('label').length).toBeGreaterThan(0)
    })
})
