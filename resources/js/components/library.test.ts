import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import { fakeClient, folder, media } from '../vue/fake.test-utils'
import MhMediaLibrary from './MhMediaLibrary.vue'

async function settle(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

async function library(over: Parameters<ReturnType<typeof fakeClient>['answerBrowse']>[0] = {}) {
    const api = fakeClient()
    api.answerBrowse({ media: [media('m1'), media('m2')], folders: [folder('f1')], ...over })

    const wrapper = mount(MhMediaLibrary, { props: { client: api }, attachTo: document.body })

    await settle()

    return { wrapper, api }
}

/**
 * THE SCREEN, ASSEMBLED FROM THE LAYERS BELOW IT.
 *
 * ⚠️ THIS FILE IS ALSO THE CHECK ON THE DESIGN. The component under test is wiring and nothing
 * else: if the screen had needed to reach past a composable — to hold state one of them already
 * holds, or to make a request of its own — the cut would have been wrong, and this is where that
 * shows rather than three lots later.
 */
describe('the library screen', () => {
    it('loads the listing and the quota when it appears', async () => {
        const { api } = await library()

        const methods = api.calls.map((call) => call.method)

        expect(methods).toContain('browse')
        expect(methods).toContain('quota')
    })

    it('shows the folders and the media it was given', async () => {
        const { wrapper } = await library()

        expect(wrapper.findAll('[role="option"]')).toHaveLength(2)
        expect(wrapper.find('nav[aria-label="Folders"]').exists()).toBe(true)
    })

    it('walks into a folder when one is opened', async () => {
        const { wrapper, api } = await library()

        await wrapper.find('nav[aria-label="Folders"] button').trigger('click')
        await settle()

        const browsed = api.calls.filter((call) => call.method === 'browse')

        expect((browsed.at(-1)?.args[0] as { folder?: unknown }).folder).toBe('f1')
    })

    /**
     * ⚠️ THE SELECTION IS DROPPED WHEN THE FOLDER CHANGES. Carrying it across means a batch
     * action runs on files nobody can see any more — and the confirmation names a count rather
     * than the files, so nothing on screen would give it away.
     */
    it('forgets the selection on the way into a folder', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(true)

        await wrapper.find('nav[aria-label="Folders"] button').trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    it('shows the toolbar of actions once something is ticked', async () => {
        const { wrapper } = await library()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').text()).toContain('Move to trash')
    })

    it('searches through the browser rather than filtering on screen', async () => {
        const { wrapper, api } = await library()

        await wrapper.find('input[type="search"]').setValue('invoice')
        await wrapper.find('form[role="search"]').trigger('submit')
        await settle()

        const browsed = api.calls.filter((call) => call.method === 'browse')

        expect((browsed.at(-1)?.args[0] as { search?: unknown }).search).toBe('invoice')
    })

    /**
     * ⚠️ A SEARCH THAT RETURNS NOTHING IS NOT AN EMPTY FOLDER. Saying "nothing here yet" to
     * somebody who has just searched suggests their files are gone.
     */
    it('says which emptiness it is', async () => {
        const { wrapper, api } = await library({ media: [], folders: [] })

        expect(wrapper.text()).toContain('Nothing here yet')

        api.answerBrowse({ media: [], folders: [] })
        await wrapper.find('input[type="search"]').setValue('nothing at all')
        await wrapper.find('form[role="search"]').trigger('submit')
        await settle()

        expect(wrapper.text()).toContain('No results')
    })

    it('sends an upload into the folder being looked at', async () => {
        const { wrapper, api } = await library()

        api.answerBrowse({ folder: folder('f1'), media: [] })
        await wrapper.find('nav[aria-label="Folders"] button').trigger('click')
        await settle()

        const input = wrapper.find('input[type="file"]')
        const file = new File(['bytes'], 'photo.png', { type: 'image/png' })

        Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
        await input.trigger('change')
        await settle()

        expect(wrapper.text()).toContain('photo.png')
    })

    /**
     * ⚠️ THE MENU IS NOT OFFERED OVER NOTHING. Opening an empty box in place of the browser's own
     * menu, on a right click on the background, takes something away and gives nothing back.
     */
    it('offers no context menu while nothing is selected', async () => {
        const { wrapper } = await library()

        await wrapper.trigger('contextmenu')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    })

    it('offers it once something is', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()
        await wrapper.trigger('contextmenu')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    })

    it('opens the details of a file that was activated', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('dblclick')
        await settle()

        expect(wrapper.find('aside').exists()).toBe(true)
    })

    it('reports a refusal rather than an empty screen', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(403, 'forbidden', 'You may not read this.'))

        const wrapper = mount(MhMediaLibrary, { props: { client: api } })
        await settle()

        expect(wrapper.find('[role="alert"]').text()).toContain('You may not read this.')
    })

    /**
     * ⚠️ AN ACTION REFRESHES BOTH THE LISTING AND THE QUOTA. Deleting a gigabyte and leaving the
     * gauge where it was tells somebody the deletion did not work, and they do it again.
     */
    it('refreshes the listing and the quota after an action', async () => {
        const { wrapper, api } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        const before = api.calls.filter((call) => call.method === 'quota').length

        await wrapper.find('[role="toolbar"] button').trigger('click')
        await settle()
        await wrapper.findAll('dialog button')[1]?.trigger('click')
        await settle()

        expect(api.calls.filter((call) => call.method === 'quota').length).toBeGreaterThan(before)
    })
})
