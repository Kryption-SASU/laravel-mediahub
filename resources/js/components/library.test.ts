import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import { fakeClient, folder, media } from '../vue/fake.test-utils'
import MhConfirmDialog from './MhConfirmDialog.vue'
import MhFolderCreator from './MhFolderCreator.vue'
import MhMediaLibrary from './MhMediaLibrary.vue'
import MhUploadButton from './MhUploadButton.vue'

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
 * ONE UPLOAD, DRIVEN ALL THE WAY THROUGH THE REAL SEAM.
 *
 * ⚠️ `XMLHttpRequest` IS WHAT IS STOOD IN FOR, NOT THE QUEUE. The screen builds its own queue and
 * takes no options, so there is nothing to inject — and standing in further up would leave the
 * transport, the reading of the body and the "a 201 whose body refused the file is a failure"
 * rule outside anything this checks.
 *
 * ⚠️ AND IT IS PUT BACK AFTERWARDS, in a `finally`. A global left replaced by a test that threw
 * takes down every later file in the run, and the failure names the wrong one.
 */
async function upload(
    wrapper: ReturnType<typeof mount>,
    answer: { status: number; body: unknown },
): Promise<void> {
    const original = globalThis.XMLHttpRequest

    class FakeRequest {
        public status = answer.status

        public responseText = JSON.stringify(answer.body)

        public upload = { addEventListener: (): void => {} }

        private readonly handlers: Record<string, Array<() => void>> = {}

        public open(): void {}

        public setRequestHeader(): void {}

        public abort(): void {}

        public addEventListener(name: string, handler: () => void): void {
            this.handlers[name] = [...(this.handlers[name] ?? []), handler]
        }

        public send(): void {
            queueMicrotask(() => {
                for (const handler of this.handlers['load'] ?? []) {
                    handler()
                }
            })
        }
    }

    globalThis.XMLHttpRequest = FakeRequest as unknown as typeof XMLHttpRequest

    try {
        const input = wrapper.findComponent(MhUploadButton).find('input[type="file"]')

        Object.defineProperty(input.element, 'files', {
            value: [new File(['bytes'], 'photo.png', { type: 'image/png' })],
            configurable: true,
        })

        Object.defineProperty(input.element, 'value', {
            value: '',
            writable: true,
            configurable: true,
        })

        await input.trigger('change')
        await settle()
        await settle()
    } finally {
        globalThis.XMLHttpRequest = original
    }
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

    /**
     * ⚠️ BOTH ROUTES TO ADDING A FILE ARE ON THE SCREEN AT ONCE. Dragging cannot be done from a
     * keyboard and is impossible on most touch devices; the drop zone alone would leave a library
     * only a mouse can put anything into.
     */
    it('offers a file picker and a way to make a folder', async () => {
        const { wrapper } = await library()

        expect(wrapper.findComponent(MhUploadButton).exists()).toBe(true)
        expect(wrapper.findComponent(MhFolderCreator).exists()).toBe(true)
    })

    /** ⚠️ AND A NEW FOLDER IS CREATED WHERE YOU ARE, not at the root of the library. */
    it('creates a folder under the one being looked at', async () => {
        const { wrapper } = await library({ folder: folder('f9', { name: 'Acme' }) })

        expect(wrapper.findComponent(MhFolderCreator).props('parent')).toMatchObject({ id: 'f9' })
    })

    /** ⚠️ AND A FOLDER THAT WAS JUST MADE HAS TO APPEAR: the listing is asked for again. */
    it('reloads the listing once a folder has been made', async () => {
        const { wrapper, api } = await library()
        const before = api.calls.filter((call) => call.method === 'browse').length

        wrapper.findComponent(MhFolderCreator).vm.$emit('created', folder('new'))
        await settle()

        expect(api.calls.filter((call) => call.method === 'browse').length).toBeGreaterThan(before)
    })

    /**
     * ⚠️ A FILE THAT LANDED HAS TO APPEAR. The queue said "done" and the grid went on showing
     * what it had loaded when the screen opened; the only way to see the upload was to reload
     * the page, which reads as the upload having failed. Reported from a real library on
     * 25/08/2026.
     *
     * ⚠️ AND THE UPLOAD IS DRIVEN THROUGH THE REAL TRANSPORT SEAM. The screen builds its own
     * queue, so there is no option to pass in; standing in for `XMLHttpRequest` is what lets the
     * whole path — button, queue, transport, refresh — be exercised rather than the one watcher
     * at the end of it.
     */
    it('reloads the listing once an upload has landed', async () => {
        const { wrapper, api } = await library()
        const before = api.calls.filter((call) => call.method === 'browse').length

        await upload(wrapper, { status: 201, body: { data: [media('m9')] } })

        expect(api.calls.filter((call) => call.method === 'browse').length).toBeGreaterThan(before)
    })

    /** ⚠️ AND THE GAUGE WITH IT — bytes went out, the space left is not what it was. */
    it('reloads the quota once an upload has landed', async () => {
        const { wrapper, api } = await library()
        const before = api.calls.filter((call) => call.method === 'quota').length

        await upload(wrapper, { status: 201, body: { data: [media('m9')] } })

        expect(api.calls.filter((call) => call.method === 'quota').length).toBeGreaterThan(before)
    })

    /**
     * ⚠️ A BATCH IN WHICH NOTHING LANDED ASKS FOR NOTHING. A refusal changes no row, and a
     * listing fetched to show the same thing is a request nobody can see the point of.
     */
    it('asks for nothing when the upload was refused', async () => {
        const { wrapper, api } = await library()
        const before = api.calls.filter((call) => call.method === 'browse').length

        await upload(wrapper, { status: 422, body: { errors: [{ reason: 'too_large' }] } })

        expect(api.calls.filter((call) => call.method === 'browse').length).toBe(before)
    })

    /**
     * ⚠️ AND THE SECOND BATCH IS JUDGED ON ITSELF. The queue keeps everything that has ever
     * landed until somebody clears it, so "there is something in it" stays true after the first
     * success — and a later batch in which every file was refused would reload the listing to
     * show exactly what is already on screen. Caught by mutation on 25/08/2026: the count has to
     * be taken when the batch STARTS, not compared against zero.
     */
    it('judges each batch on what that batch landed', async () => {
        const { wrapper, api } = await library()

        await upload(wrapper, { status: 201, body: { data: [media('m9')] } })

        const after = api.calls.filter((call) => call.method === 'browse').length

        await upload(wrapper, { status: 422, body: { errors: [{ reason: 'too_large' }] } })

        expect(api.calls.filter((call) => call.method === 'browse').length).toBe(after)
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

        /* ⚠️ THE CONFIRMATION IS FOUND BY COMPONENT, NOT BY BEING THE FIRST `<dialog>` ON THE
         * PAGE. The screen carries a second one — the folder creator's, closed and therefore
         * invisible but present — and an index into `findAll('dialog button')` silently started
         * clicking Cancel on the wrong prompt. */
        await wrapper.findComponent(MhConfirmDialog).findAll('button')[1]?.trigger('click')
        await settle()

        expect(api.calls.filter((call) => call.method === 'quota').length).toBeGreaterThan(before)
    })
})
