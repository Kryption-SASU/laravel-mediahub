import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import { fakeClient, folder } from '../vue/fake.test-utils'
import MhBreadcrumb from './MhBreadcrumb.vue'
import MhFolderCreator from './MhFolderCreator.vue'
import MhFolderList from './MhFolderList.vue'
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

    /**
     * ⚠️ THE ACTIONS COME FROM THE SCREEN, AND THE TOOLBAR ONLY OFFERS THE PLACE. Uploading puts
     * files in the folder on screen and creating one puts it under that same folder; a toolbar
     * holding either would have to reach for the browser's state, and stop being a toolbar.
     */
    it('gives the screen a place for its own controls', () => {
        const wrapper = mount(MhToolbar, { slots: { start: '<button id="mine">Add</button>' } })

        expect(wrapper.find('#mine').exists()).toBe(true)
    })

    /** ⚠️ AND THE ROW IS NOT RENDERED AT ALL WHEN NOBODY FILLED IT — an empty flex child still
     * takes its share of the gap, and the toolbar comes out with a hole at the start. */
    it('renders no room for controls nobody passed', () => {
        expect(mount(MhToolbar).findAll('div').some((box) => box.element.children.length === 0))
            .toBe(false)
    })
})

describe('the folders inside a listing', () => {
    const folders = [folder('f1', { name: 'Clients' }), folder('f2', { name: 'Invoices' })]

    /**
     * ⚠️ THE TILE, NOT EVERY BUTTON IN THE LIST. Each entry now carries a menu button of its
     * own, so an index into `findAll('button')` opened the wrong thing — and the test failed
     * on the folder it reported rather than on the selector that had moved. The tile is the
     * one that says the folder's name.
     */
    function tile(wrapper: ReturnType<typeof mount>, name: string) {
        return wrapper.findAll('button').filter((button) => button.text() === name)[0]
    }

    /**
     * ⚠️ THE SAME TILE AS A FILE, AND THE SAME COLUMNS. A row of pills above a grid of pictures
     * reads as two unrelated things, and the first click somebody makes is on the breadcrumb
     * rather than on the folder they can see.
     */
    it('draws a folder rather than printing a slash', () => {
        const wrapper = mount(MhFolderList, { props: { folders } })

        expect(tile(wrapper, 'Clients')).toBeDefined()
        expect(tile(wrapper, 'Invoices')).toBeDefined()
        expect(wrapper.findAll('svg').length).toBeGreaterThanOrEqual(2)
        expect(wrapper.text()).not.toContain('/')
    })

    it('opens the one that was clicked', async () => {
        const wrapper = mount(MhFolderList, { props: { folders } })

        await tile(wrapper, 'Invoices')?.trigger('click')

        expect(wrapper.emitted('open')?.[0]?.[0]).toEqual(folders[1])
    })

    /** ⚠️ AND A HOST WITH ITS OWN ICON SET REPLACES THE DRAWING WITHOUT FORKING THE TEMPLATE. */
    it('lets a host put its own drawing in', () => {
        const wrapper = mount(MhFolderList, {
            props: { folders },
            slots: { icon: '<i class="theirs" />' },
        })

        expect(wrapper.findAll('.theirs')).toHaveLength(2)
    })

    it('shows nothing at all when there is no folder here', () => {
        expect(mount(MhFolderList, { props: { folders: [] } }).find('nav').exists()).toBe(false)
    })
})

describe('creating a folder', () => {
    function creator(props: Record<string, unknown> = {}) {
        const api = fakeClient()
        const wrapper = mount(MhFolderCreator, { props: { client: api, ...props } })

        return { wrapper, api }
    }

    async function ask(wrapper: ReturnType<typeof mount>, name: string): Promise<void> {
        await wrapper.find('button').trigger('click')
        await wrapper.find('input').setValue(name)
        await wrapper.find('form').trigger('submit')
        await nextTick()
        await nextTick()
    }

    /**
     * ⚠️ THE PARENT IS WHAT THE SCREEN SAYS IT IS. A folder created from inside "Clients/Acme"
     * that lands at the root is not a small mistake: it is invisible until somebody goes looking
     * for it, and by then files have been put in it.
     */
    it('creates it under the folder being looked at', async () => {
        const parent = folder('f1', { name: 'Acme' })
        const { wrapper, api } = creator({ parent })

        await ask(wrapper, 'Contracts')

        expect(api.calls.filter((call) => call.method === 'createFolder')[0]?.args).toEqual([
            'Contracts',
            'f1',
        ])
    })

    /** ⚠️ AND `null` IS THE ROOT, SAID OUT LOUD rather than left out of the payload. */
    it('creates it at the root when that is where you are', async () => {
        const { wrapper, api } = creator()

        await ask(wrapper, 'Contracts')

        expect(api.calls.filter((call) => call.method === 'createFolder')[0]?.args).toEqual([
            'Contracts',
            null,
        ])
    })

    it('reports the folder it made, and gets out of the way', async () => {
        const { wrapper } = creator()

        await ask(wrapper, 'Contracts')

        expect(wrapper.emitted('created')?.[0]?.[0]).toMatchObject({ name: 'Contracts' })
        expect((wrapper.find('dialog').element as HTMLDialogElement).open).toBe(false)
    })

    /** ⚠️ A NAME OF SPACES IS NOT A NAME, and it is refused before the request rather than after. */
    it('refuses a name that is only spaces', async () => {
        const { wrapper, api } = creator()

        await wrapper.find('button').trigger('click')
        await wrapper.find('input').setValue('   ')

        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

        await wrapper.find('form').trigger('submit')
        await nextTick()

        expect(api.calls.filter((call) => call.method === 'createFolder')).toHaveLength(0)
    })

    /**
     * ⚠️ A REFUSAL LEAVES THE DIALOG OPEN, WITH THE NAME STILL IN IT. Closing on a failure takes
     * the typed name away, and the message explaining why lands on a screen that has moved on.
     */
    it('keeps the prompt and says why when the server refuses', async () => {
        const { wrapper, api } = creator()
        api.failWith(new MediaHubError(422, 'taken', 'A folder already goes by that name.'))

        await ask(wrapper, 'Contracts')

        expect(wrapper.emitted('created')).toBeUndefined()
        expect(wrapper.find('[role="alert"]').text()).toContain('already goes by that name')
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('Contracts')

        /* ⚠️ THE DIALOG ITSELF IS WHAT MUST STILL BE THERE. Its contents stay rendered either
         * way — closing it only hides them — so asserting on the field alone passes against a
         * component that dismissed the prompt under the message explaining the failure. */
        expect((wrapper.find('dialog').element as HTMLDialogElement).open).toBe(true)
    })
})
