import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import { deferred, fakeClient, folder, media } from '../vue/fake.test-utils'
import MhConfirmDialog from './MhConfirmDialog.vue'
import MhContextMenu from './MhContextMenu.vue'
import MhDetailsDialog from './MhDetailsDialog.vue'
import MhFolderCreator from './MhFolderCreator.vue'
import MhFolderList from './MhFolderList.vue'
import MhLightbox from './MhLightbox.vue'
import MhRenamer from './MhRenamer.vue'
import MhMediaLibrary from './MhMediaLibrary.vue'
import MhUploadButton from './MhUploadButton.vue'

async function settle(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

/**
 * ⚠️ THE FOLDER'S TILE, BY THE NAME IT SHOWS. Each entry carries a menu button as well now,
 * and it comes first in the markup — `nav button` reached that one instead, so walking into
 * a folder stopped happening and the failure named a browse call rather than a selector.
 */
function folderTile(wrapper: ReturnType<typeof mount>, name = 'f1') {
    return wrapper
        .findAll('nav[aria-label="Folders"] button')
        .filter((button) => button.text() === name)[0]
}

/**
 * ⚠️ THE WINDOW'S OWN STATE, NOT THE PRESENCE OF ITS MARKUP. The `<dialog>` element is always in
 * the tree — that is how a native dialog works — and only `open` says whether anybody can see it.
 *
 * ⚠️ AND IT IS FOUND BY COMPONENT, NOT BY SELECTOR. This screen carries three dialogs — the
 * confirmation, the folder creator, this one — and every one of them has an `aria-label`, so
 * `find('dialog[aria-label]')` answers with whichever happens to come first in the tree. It
 * answered with the folder creator, which is never open, and the assertion passed for the wrong
 * reason in one test and failed for the wrong reason in another.
 */
function detailsWindow(wrapper: ReturnType<typeof mount>) {
    return wrapper.findComponent(MhDetailsDialog).find('dialog')
}

function detailsOpen(wrapper: ReturnType<typeof mount>): boolean {
    return (detailsWindow(wrapper).element as HTMLDialogElement).open
}

/**
 * ⚠️ TICKING SOMETHING NOW TAKES TWO STEPS, and that is the point of the mode. Browsing, a click
 * shows the file and ticks nothing; nothing on the screen adds to a batch until somebody has said
 * they are choosing.
 */
async function pick(wrapper: ReturnType<typeof mount>, positions: number[]): Promise<void> {
    const start = wrapper.findAll('button').filter((b) => b.text() === 'Select')[0]

    await start?.trigger('click')
    await settle()

    for (const position of positions) {
        await wrapper.findAll('[role="option"]')[position]?.trigger('click')
    }

    await settle()
}

/**
 * AGREEING TO THE QUESTION THAT IS ACTUALLY ON SCREEN.
 *
 * ⚠️ THIS SCREEN CARRIES TWO CONFIRMATIONS, one per renderer — the batch bar has its own runner
 * and so does the context menu — and both are in the tree with their buttons rendered whether or
 * not they are open. `findAll('dialog button')` therefore answers with the bar's "Confirm" first,
 * and clicking it does nothing at all: its runner has nothing pending. The act never ran, the
 * assertion failed, and the failure named the state it was checking rather than the click that
 * never landed.
 *
 * ⚠️ THE OPEN ONE IS THE ONE ASKING. Only one question can be put at a time, so that is the
 * whole of the rule — and it stays true if a third renderer is ever added.
 */
async function agree(wrapper: ReturnType<typeof mount>): Promise<void> {
    const asking = wrapper
        .findAll('dialog')
        .filter((one) => (one.element as HTMLDialogElement).open)[0]

    await asking?.findAll('button').filter((one) => one.text() === 'Confirm')[0]?.trigger('click')
}

/**
 * ⚠️ FOUND BY WHAT IT ANNOUNCES, since it carries no text. It is one glyph everybody recognises,
 * so the label is what a screen reader — and this bench — has to go on.
 */
function trashDoor(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAll('button')
        .filter((button) => ['Trash', 'Leave the trash'].includes(button.attributes('aria-label') ?? ''))[0]
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

        await folderTile(wrapper)?.trigger('click')
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

        await pick(wrapper, [0])

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(true)

        /* ⚠️ Opening a folder while choosing is not offered, so the walk goes through the
         * browser the way leaving the mode would. */
        await wrapper.findAll('button').filter((b) => b.text() === 'Done')[0]?.trigger('click')
        await folderTile(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /**
     * ⚠️ THE BATCH BAR IS RESERVED FOR SELECTION MODE. It used to appear the moment anything was
     * clicked, which meant looking at a file and choosing it were the same gesture — and a bar
     * offering to trash "1 selected" stood over a screen where somebody had only looked.
     */
    it('shows the batch bar only once somebody is choosing', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)

        await pick(wrapper, [0])

        expect(wrapper.find('[role="toolbar"]').text()).toContain('Move to trash')
    })

    /** ⚠️ AND LEAVING THE MODE LETS GO OF WHAT WAS TICKED — a selection nobody can see is one
     * that acts by surprise the next time the bar comes back. */
    it('lets go of the selection when the mode is left', async () => {
        const { wrapper } = await library()

        await pick(wrapper, [0])
        await wrapper.findAll('button').filter((b) => b.text() === 'Done')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /** ⚠️ A FOLDER IS TICKABLE TOO — a batch that could act on files but never on the folder
     * holding them is a rule nobody can see and everybody trips over. */
    it('ticks a folder while choosing, rather than walking into it', async () => {
        const { wrapper, api } = await library()

        const before = api.calls.filter((call) => call.method === 'browse').length

        await wrapper.findAll('button').filter((b) => b.text() === 'Select')[0]?.trigger('click')
        await settle()
        await folderTile(wrapper)?.trigger('click')
        await settle()

        expect(api.calls.filter((call) => call.method === 'browse').length).toBe(before)
        /* ⚠️ The bar prints the number and says the sentence to assistive technology; the
         * count is therefore read from the label rather than from what is on screen. */
        expect(wrapper.find('[role="toolbar"]').attributes('aria-label')).toBe('1 selected')
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
        await folderTile(wrapper)?.trigger('click')
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

    /** ⚠️ AND A CHOSEN FOLDER SAYS SO ON ITSELF — the same mark a chosen file wears. */
    it('marks a folder that has been ticked', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('button').filter((b) => b.text() === 'Select')[0]?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhFolderList).find('svg[stroke-width="2.5"]').exists()).toBe(false)

        await folderTile(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhFolderList).find('svg[stroke-width="2.5"]').exists()).toBe(true)
    })

    /**
     * ⚠️ COMING BACK STARTS FROM NOTHING. A selection kept while the mode is off is one nobody can
     * see; the bar that returns with it offers to act on a list made minutes ago and forgotten.
     */
    it('starts from nothing when the mode is entered again', async () => {
        const { wrapper } = await library()

        await pick(wrapper, [0])
        await wrapper.findAll('button').filter((b) => b.text() === 'Done')[0]?.trigger('click')
        await settle()

        await wrapper.findAll('button').filter((b) => b.text() === 'Select')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /** ⚠️ AND IT IS NOT OFFERED AT ALL WHILE CHOOSING: in that mode a tile does one thing. */
    it('offers no context menu while somebody is choosing', async () => {
        const { wrapper } = await library()

        await pick(wrapper, [0])
        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    })

    /**
     * ⚠️ ONE CLICK SHOWS THE FILE. Asking for a double one leaves the panel empty for anybody who
     * does not think to try, and the first click reads as having done nothing at all.
     */
    it('shows a file the moment it is clicked', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('aside').attributes('aria-label')).toBe('m1')
    })

    /**
     * ⚠️ AND IT KEEPS SHOWING IT WHEN THE SECOND CLICK UNTICKS IT. "Show me this one" and "count
     * this one in" are two intentions; emptying the panel would answer a question nobody asked.
     */
    it('goes on showing it when the same tile is clicked twice', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('aside').attributes('aria-label')).toBe('m1')
    })

    /**
     * ⚠️ ASKING WHAT CAN BE DONE TO A FILE IS NOT ASKING TO LOOK AT IT. A right click that
     * swapped the panel replaces what somebody had deliberately left on screen to compare
     * against — and answers a question they did not put.
     */
    it('leaves the panel on the file it was showing when the actions are asked for', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        await wrapper.findAll('[role="option"]')[1]?.trigger('contextmenu')
        await settle()

        expect(wrapper.find('aside').attributes('aria-label')).toBe('m1')
    })

    /** ⚠️ AND SHOWS NOTHING WHEN THAT IS THE FIRST THING SOMEBODY DOES. */
    it('shows nothing when the actions are the first thing asked for', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        expect(wrapper.find('aside').exists()).toBe(false)
    })

    /**
     * ⚠️ THE SAME GOES FOR THE BUTTON ON THE TILE. It sits inside the card, so its click reaches
     * the card underneath unless it is stopped — and the tile then ticks itself and fills the
     * panel, which is the opposite of what pressing a menu button asks for.
     */
    it('shows nothing when the tile menu button is what was pressed', async () => {
        const { wrapper } = await library()

        const trigger = wrapper.findAll('[role="option"]')[0]?.find('button[aria-label="Actions"]')

        await trigger?.trigger('click')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(true)
        expect(wrapper.find('aside').exists()).toBe(false)
    })

    /**
     * ⚠️ THE ACTIONS ARE ASKED FOR ON THE THING THEY ACT ON. They used to be reachable only by
     * right-clicking the background once something was already ticked — a rule nobody discovers,
     * on a screen where the obvious move is to right-click the file you mean.
     */
    it('offers the actions of a file that was never ticked', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[1]?.trigger('contextmenu')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(true)
        expect(wrapper.findComponent(MhContextMenu).props('selection')).toEqual({ media: ['m2'] })

        /* ⚠️ AND IT TICKED NOTHING. The menu used to write into the same list the batch bar reads,
         * so a right click raised a bar offering to act on a file nobody had chosen. */
        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /** ⚠️ AND A FOLDER HAS THEM TOO — half a grid that answers nothing reads as decoration. */
    it('offers the actions of a folder', async () => {
        const { wrapper } = await library()

        await folderTile(wrapper)?.trigger('contextmenu')
        await settle()

        expect(wrapper.findComponent(MhContextMenu).props('selection')).toEqual({
            folders: ['f1'],
        })
    })

    /**
     * ⚠️ A BATCH IS BUILT IN SELECTION MODE AND ACTED ON FROM THE BAR — not from a menu. The two
     * used to share one list, so the menu had to guess whether a right click meant "this one" or
     * "the five I ticked". They are separate now, and neither has to guess.
     */
    it('builds a batch of several while choosing', async () => {
        const { wrapper } = await library()

        await pick(wrapper, [0, 1])

        expect(wrapper.find('[role="toolbar"]').attributes('aria-label')).toBe('2 selected')
    })

    /**
     * ⚠️ A MENU CLOSES WHEN YOU CLICK AWAY FROM IT, and that was the only route out it did not
     * have. Escape closed it and choosing an action closed it, but somebody who opened it by
     * mistake was left with it floating over the screen for as long as the page lived — which is
     * how it was reported: a menu that never closes.
     */
    it('closes when the pointer goes down outside it', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(true)

        document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }))
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    })

    /**
     * ⚠️ AND NOT WHEN IT GOES DOWN INSIDE IT. Closing on the way down would take the box away
     * before the `click` that chooses an action ever reached it — a menu that cannot be used.
     */
    it('stays open while the pointer goes down on one of its own items', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        await wrapper.findAll('[role="menuitem"]')[0]?.trigger('pointerdown')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    })

    /** ⚠️ AND CHOOSING SOMETHING CLOSES IT TOO — the route that did already work stays working. */
    it('closes once an action has been chosen', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        /* ⚠️ THE LIBRARY IS NOT THE TRASH, so the only action offered here is the one that puts
         * something in it. The menu closes before the confirmation opens, which is what this
         * checks — the two are separate elements. */
        const trash = wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Move to trash')[0]

        await trash?.trigger('click')
        await settle()

        expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    })

    /**
     * ⚠️ THE DETAILS ARE A WINDOW, NOT A COLUMN. A panel down the side costs every other file a
     * fifth of the room they had, all the time, for something looked at now and then.
     */
    it('opens a window on the file, and closes it on the close button', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(true)

        const close = wrapper.findAll('button').filter((b) => b.attributes('aria-label') === 'Close')

        await close[0]?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(false)
    })

    /**
     * ⚠️ AND ON A CLICK ON THE BACKDROP, which is the dialog element itself — that is the only way
     * to tell it apart from a click on the contents, and why this cannot be done with `contains()`.
     */
    it('closes the window on a click outside its contents', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        await detailsWindow(wrapper).trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(false)
    })

    /** ⚠️ BUT NOT ON A CLICK ON WHAT IT CONTAINS — a window that shuts as you reach into it. */
    it('stays open when the click lands on what it is showing', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        await wrapper.find('aside').trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(true)
    })

    /**
     * ⚠️ THE LISTING WAS SILENTLY CUT UNTIL THE SCREEN ASKED FOR A SECOND PAGE. The server
     * paginated from the first day and nothing ever went past the first: a folder of three hundred
     * files showed forty-eight and said nothing about the rest. An absent feature is noticed; a cut
     * listing is not.
     */
    it('asks for another page when one is chosen', async () => {
        const { wrapper, api } = await library({
            meta: { current_page: 1, last_page: 3, per_page: 48, total: 120 },
        })

        await wrapper.findAll('button').filter((b) => b.text() === '2')[0]?.trigger('click')
        await settle()

        const browsed = api.calls.filter((call) => call.method === 'browse')

        expect((browsed.at(-1)?.args[0] as { page?: unknown }).page).toBe(2)
    })

    /**
     * ⚠️ A PAGE IS A PLACE, AND LEAVING IT LETS GO OF WHAT BELONGED TO IT. The selection would
     * otherwise carry files nobody can see any more into a batch whose confirmation names a count
     * rather than the files.
     */
    it('lets go of the selection when the page changes', async () => {
        const { wrapper } = await library({
            meta: { current_page: 1, last_page: 3, per_page: 48, total: 120 },
        })

        await pick(wrapper, [0])

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(true)

        await wrapper.findAll('button').filter((b) => b.text() === '2')[0]?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /** ⚠️ AND THE WINDOW WITH IT: the file it was showing is not in this page. */
    it('closes the details window when the page changes', async () => {
        const { wrapper } = await library({
            meta: { current_page: 1, last_page: 3, per_page: 48, total: 120 },
        })

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(true)

        await wrapper.findAll('button').filter((b) => b.text() === '2')[0]?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(false)
    })

    /**
     * ⚠️ THE TRASH WAS REACHABLE FROM NOWHERE AT ALL. Everything the API needed was already
     * there — browsing accepts `trashed`, restoring walks a folder's subtree and its ancestors —
     * and no screen ever asked for it.
     */
    it('opens the trash from the toolbar', async () => {
        const { wrapper, api } = await library()

        await trashDoor(wrapper)?.trigger('click')
        await settle()

        const browsed = api.calls.filter((call) => call.method === 'browse')

        expect((browsed.at(-1)?.args[0] as { trashed?: unknown }).trashed).toBe(true)
    })

    it('comes back out of it', async () => {
        const { wrapper, api } = await library()

        await trashDoor(wrapper)?.trigger('click')
        await settle()
        await trashDoor(wrapper)?.trigger('click')
        await settle()

        const browsed = api.calls.filter((call) => call.method === 'browse')

        expect((browsed.at(-1)?.args[0] as { trashed?: unknown }).trashed).toBe(false)
    })

    /**
     * ⚠️ NOTHING IS ADDED TO A TRASH. Depositing a file there would mean uploading something
     * already thrown away, and a new folder would be born deleted. Both controls were offered
     * anyway, and both would have acted: the upload would have landed in the library behind, out
     * of sight of the screen that accepted it.
     */
    it('offers nothing to add once inside the trash', async () => {
        const { wrapper } = await library()

        expect(wrapper.findComponent(MhUploadButton).exists()).toBe(true)
        expect(wrapper.findComponent(MhFolderCreator).exists()).toBe(true)

        await trashDoor(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhUploadButton).exists()).toBe(false)
        expect(wrapper.findComponent(MhFolderCreator).exists()).toBe(false)
    })

    /** ⚠️ AND THEY COME BACK ON THE WAY OUT — a door, not a one-way trip. */
    it('offers them again once out of it', async () => {
        const { wrapper } = await library()

        await trashDoor(wrapper)?.trigger('click')
        await settle()
        await trashDoor(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhUploadButton).exists()).toBe(true)
    })

    /**
     * ⚠️ AND THE WORDLESS CONTROLS STILL HAVE A NAME. A toolbar of glyphs reads as a tool; a
     * toolbar of glyphs with nothing to announce is a row of unnamed buttons to anybody not
     * looking at it. The text is hidden by the theme, never taken out of the markup.
     */
    it('names the controls that carry no visible text', async () => {
        const { wrapper } = await library()

        expect(wrapper.findComponent(MhUploadButton).text()).toContain('Add files')
        expect(wrapper.findComponent(MhFolderCreator).text()).toContain('New folder')
    })

    /**
     * ⚠️ EACH SIDE DROPS THE ONE ENTRY THAT MEANS NOTHING ON IT — putting away what is already
     * away, taking back what was never thrown away. Deleting for good stays on both.
     */
    it('swaps putting away for taking back inside the trash', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        /* ⚠️ THE WHOLE ORDINARY MENU, and the screen is what lends the two entries that are a
         * surface rather than a request — a bare menu offers neither. Deleting for good is not
         * here: it belongs to the trash now. */
        expect(wrapper.findAll('[role="menuitem"]').map((item) => item.text())).toEqual([
            'Preview',
            'Copy link',
            'Rename',
            'Duplicate',
            'Download',
            'Move to trash',
        ])

        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' })
        await trashDoor(wrapper)?.trigger('click')
        await settle()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        expect(wrapper.findAll('[role="menuitem"]').map((item) => item.text())).toEqual([
            'Restore',
            'Delete permanently',
        ])
    })

    /**
     * ⚠️ THE MENU ENTRY HAS TO REACH THE SURFACE, and nothing else proves it does. The action
     * list only knows it was lent a function; that the function opens this screen's viewer, on
     * the file that was right-clicked rather than on whatever the panel last showed, is a fact
     * about the wiring — which is the one thing a bench on the list itself cannot see.
     */
    it('opens the viewer on the file the menu was opened on', async () => {
        const { wrapper } = await library({ media: [media('m1'), media('m2')] })

        await wrapper.findAll('[role="option"]')[1]?.trigger('contextmenu')
        await settle()

        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Preview')[0]
            ?.trigger('click')
        await settle()

        const viewer = wrapper.findComponent(MhLightbox)

        expect((viewer.props('media') as { id: string } | null)?.id).toBe('m2')
        expect((viewer.find('dialog').element as HTMLDialogElement).open).toBe(true)
    })

    it('opens the rename prompt on the file the menu was opened on', async () => {
        const { wrapper } = await library({ media: [media('m1', { name: 'Invoice' })] })

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Rename')[0]
            ?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhRenamer).props('target')).toEqual({
            kind: 'media',
            id: 'm1',
            name: 'Invoice',
        })
    })

    /**
     * ⚠️ AND ON A FOLDER, WHICH IS THE HALF THAT WAS REACHABLE FROM NOWHERE. A folder's name
     * could not be changed at all: the details window is for files, and nothing else offered it.
     * The kind travels with the target, because the two are renamed through different endpoints.
     */
    it('opens the rename prompt on a folder as well', async () => {
        const { wrapper } = await library({ folders: [folder('f1', { name: 'Clients' })] })

        await folderTile(wrapper, 'Clients')?.trigger('contextmenu')
        await settle()

        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Rename')[0]
            ?.trigger('click')
        await settle()

        expect(wrapper.findComponent(MhRenamer).props('target')).toEqual({
            kind: 'folder',
            id: 'f1',
            name: 'Clients',
        })
    })


    /**
     * ⚠️ NEITHER SIDE COULD SHOW THIS ALONE, which is the whole reason it is wired rather than
     * drawn. The menu knows an act is running and knows nothing about where on screen the files
     * it names are; the screen knows the opposite. Half of each is why duplicating a large file
     * gave no sign at all until the copy appeared.
     */
    it('marks the tile it is duplicating, and lets go when it is done', async () => {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1'), media('m2')], folders: [] })

        const held = deferred<unknown>()
        const holding = { ...api, copy: () => held.promise as Promise<never> }

        const wrapper = mount(MhMediaLibrary, {
            props: { client: holding },
            attachTo: document.body,
        })

        await settle()

        await wrapper.findAll('[role="option"]')[0]?.trigger('contextmenu')
        await settle()

        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Duplicate')[0]
            ?.trigger('click')
        await settle()

        expect(
            wrapper.findAll('[role="option"]').map((one) => one.attributes('aria-busy')),
        ).toEqual(['true', undefined])

        held.resolve(media('m1-copy'))
        await settle()

        expect(
            wrapper.findAll('[role="option"]').map((one) => one.attributes('aria-busy')),
        ).toEqual([undefined, undefined])
    })

    /**
     * ⚠️ AND A BATCH MARKS EVERY FILE IT NAMES. The wait comes from a second renderer here — the
     * quick-action bar rather than the menu — and the screen has to listen to both: a mutation
     * that deafened it to the bar stayed green while the menu's half was covered, which is
     * exactly the kind of half-wiring that ships.
     */
    it('marks every file a batch is working on', async () => {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1'), media('m2'), media('m3')], folders: [] })

        const held = deferred<unknown>()
        const holding = { ...api, trash: () => held.promise as Promise<never> }

        const wrapper = mount(MhMediaLibrary, {
            props: { client: holding },
            attachTo: document.body,
        })

        await settle()
        await pick(wrapper, [0, 2])

        await wrapper
            .findAll('[role="toolbar"] button')
            .filter((one) => one.text() === 'Move to trash')[0]
            ?.trigger('click')
        await settle()

        await agree(wrapper)
        await settle()

        expect(
            wrapper.findAll('[role="option"]').map((one) => one.attributes('aria-busy') ?? 'no'),
        ).toEqual(['true', 'no', 'true'])

        held.resolve({ count: 2 })
        await settle()

        expect(
            wrapper.findAll('[role="option"]').map((one) => one.attributes('aria-busy') ?? 'no'),
        ).toEqual(['no', 'no', 'no'])
    })

    /**
     * ⚠️ AND A FOLDER IS MARKED THE SAME WAY. Trashing one takes its whole subtree and is the
     * slowest act on this screen — the one most in need of a sign that something is happening —
     * and it travels through a different component from the files. A mutation that stopped the
     * screen handing the folders over stayed green until this existed.
     */
    it('marks the folder it is working on', async () => {
        const api = fakeClient()
        api.answerBrowse({ media: [], folders: [folder('f1')] })

        const held = deferred<unknown>()
        const holding = { ...api, trash: () => held.promise as Promise<never> }

        const wrapper = mount(MhMediaLibrary, {
            props: { client: holding },
            attachTo: document.body,
        })

        await settle()

        await folderTile(wrapper)?.trigger('contextmenu')
        await settle()

        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Move to trash')[0]
            ?.trigger('click')
        await settle()

        /* The question first — trashing a folder always asks. */
        await agree(wrapper)
        await settle()

        expect(wrapper.find('nav[aria-label="Folders"] button[aria-busy="true"]').exists()).toBe(
            true,
        )

        held.resolve({ count: 1 })
        await settle()

        expect(wrapper.find('nav[aria-label="Folders"] button[aria-busy="true"]').exists()).toBe(
            false,
        )
    })

    /**
     * ⚠️ CROSSING OVER LETS GO OF EVERYTHING. What was ticked on one side means nothing on the
     * other — the actions are not even the same — and a batch carried across would act on files
     * the screen behind it no longer lists.
     */
    it('lets go of what was chosen on the way in', async () => {
        const { wrapper } = await library()

        await pick(wrapper, [0])

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(true)

        await trashDoor(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
    })

    /** ⚠️ AND THE WINDOW WITH IT: the file it was showing is not in this listing. */
    it('closes the details window on the way in', async () => {
        const { wrapper } = await library()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(true)

        await trashDoor(wrapper)?.trigger('click')
        await settle()

        expect(detailsOpen(wrapper)).toBe(false)
    })

    /**
     * ⚠️ AN EMPTY TRASH IS NOT AN EMPTY LIBRARY. "Nothing here yet" told somebody looking at one
     * that they had never uploaded anything.
     */
    it('says which emptiness the trash is', async () => {
        const { wrapper, api } = await library({ media: [], folders: [] })

        api.answerBrowse({ media: [], folders: [] })
        await trashDoor(wrapper)?.trigger('click')
        await settle()

        expect(wrapper.text()).toContain('The trash is empty')
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

        await pick(wrapper, [0])

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

/**
 * OPENED TO CHOOSE ONE KIND OF FILE.
 *
 * ⚠️ THE CALLER'S DECISION, NOT THE VIEWER'S. A host opening this screen as "choose a video" has
 * already answered the question the type control asks; leaving it there lets somebody widen the
 * list back to everything and pick the one file the caller said it could not use.
 */
describe('a library restricted to some kinds', () => {
    async function restricted(types: readonly string[]) {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1')], folders: [] })

        const wrapper = mount(MhMediaLibrary, {
            props: { client: api, types: types as never },
            attachTo: document.body,
        })

        await settle()

        return { wrapper, api }
    }

    /**
     * ⚠️ ASKED FOR ONCE, WITH THE RESTRICTION ALREADY ON IT. Loading everything and filtering
     * afterwards asks the server twice and shows the unrestricted answer in between — a flash of
     * every file on a screen that was opened to choose a video.
     */
    it('asks the server for that kind from the first request', async () => {
        const { api } = await restricted(['video'])

        const browses = api.calls.filter((call) => call.method === 'browse')

        expect(browses).toHaveLength(1)
        expect((browses[0]?.args[0] as { types?: string[] })?.types).toEqual(['video'])
    })

    /** ⚠️ AND THE CONTROL THAT WOULD UNDO IT IS NOT THERE. */
    it('offers no way to widen it back', async () => {
        const { wrapper } = await restricted(['video'])

        const labels = wrapper.findAll('label').map((one) => one.text())

        expect(labels.join(' ')).not.toContain('Kind')
    })

    /** ⚠️ AND AN UNRESTRICTED LIBRARY KEEPS ITS CONTROL, which is what makes the absence a
     * decision rather than a removal. */
    it('leaves the control alone when nothing was restricted', async () => {
        const { wrapper } = await library()

        const labels = wrapper.findAll('label').map((one) => one.text())

        expect(labels.join(' ')).toContain('Kind')
    })

    /**
     * ⚠️ IT FOLLOWS THE CALLER RATHER THAN BEING READ ONCE. The same dialog serves two tabs in a
     * host application: it stays mounted and the kind changes underneath. Read only at mount, the
     * second tab would list what the first one asked for.
     */
    it('follows the caller when the kind changes underneath', async () => {
        const { wrapper, api } = await restricted(['video'])

        await wrapper.setProps({ types: ['image'] as never })
        await settle()

        const last = api.calls.filter((call) => call.method === 'browse').at(-1)

        expect((last?.args[0] as { types?: string[] })?.types).toEqual(['image'])
    })
})
