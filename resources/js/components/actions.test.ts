import { mount } from '@vue/test-utils'
import { h, nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import type { Selection } from '../client'
import { fakeClient } from '../vue/fake.test-utils'
import type { MhAction } from './actions'
import MhContextMenu from './MhContextMenu.vue'
import MhProvider from './MhProvider.vue'
import MhSelectionBar from './MhSelectionBar.vue'
import { useMediaActionList } from './actions'
import { useActionRunner } from './useActionRunner'

const one: Selection = { media: ['m1'] }

/**
 * The wording of the controls at a given place.
 *
 * ⚠️ BUTTONS INSIDE A DIALOG ARE NOT PART OF THE PLACE THEY SIT NEXT TO. Counting them
 * would make this comparison depend on whether a confirmation happens to be mounted, and the
 * test would then report a difference that does not exist.
 */
function labels(wrapper: ReturnType<typeof mount>, selector: string): string[] {
    return wrapper
        .findAll(selector)
        .filter((node) => node.element.closest("dialog") === null)
        .map((node) => node.text())
}

function bar(props: Record<string, unknown> = {}) {
    return mount(MhSelectionBar, {
        props: { selection: one, client: fakeClient(), ...props },
        attachTo: document.body,
    })
}

/** ⚠️ THE ONE THAT ASKS BEFORE ACTING, by its wording rather than its place in a growing row. */
function trashing(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAll('[role="toolbar"] button')
        .filter((one) => one.text() === 'Move to trash')[0]
}

function menu(props: Record<string, unknown> = {}) {
    return mount(MhContextMenu, {
        props: { open: true, selection: one, client: fakeClient(), ...props },
        attachTo: document.body,
    })
}

describe('one source for the actions', () => {
    /**
     * ⚠️ THE RULE OF THIS LOT, WRITTEN AS A TEST. Two lists that merely look alike diverge at the
     * first addition: somebody adds an action to the bar, forgets the menu, and nothing turns
     * red — the screen still works, it simply offers less depending on where you clicked, and
     * whoever notices assumes they misremembered.
     */
    it('offers exactly the same actions in the bar and in the menu', () => {
        /*
         * ⚠️ THE SAME PLACE, OR THE COMPARISON MEANS NOTHING. The two are asked the same question
         * — what can be done here — and they only have to answer alike when "here" is the same:
         * the bar is a batch being assembled and the menu is one thing being pointed at, which
         * is a real difference and one the entries are entitled to read. What is being checked
         * is that neither renderer keeps a list of its own.
         */
        const inBar = labels(bar({ picking: false }), '[role="toolbar"] button').slice(0, -1)
        const inMenu = labels(menu({ picking: false }), '[role="menuitem"]')

        expect(inMenu).toEqual(inBar)
        expect(inMenu.length).toBeGreaterThan(0)
    })

    /**
     * ⚠️ AND THE BAR, WHERE IT REALLY STANDS, OFFERS NONE OF THE SINGLE-ITEM ENTRIES. It only
     * ever appears while somebody is assembling a batch, and half of these acts have no plural:
     * a file cannot be renamed to two names, and duplicating four files is four acts. Reading
     * "one thing is ticked" instead of "a batch is being built" would show "Rename" for as long
     * as the batch happened to hold a single file and take it away at the second — a menu that
     * flickers as you work is one people stop trusting.
     */
    it('keeps the single-item entries out of the batch bar', () => {
        /* ⚠️ DOWNLOADING IS THE EXCEPTION, and a deliberate one: it is the single act with an
         * obvious meaning on a batch of one, and refusing it here would be a rule about our own
         * state rather than about what somebody asked for. */
        expect(labels(bar(), '[role="toolbar"] button').slice(0, -1)).toEqual([
            'Download',
            'Move to trash',
        ])
    })

    /**
     * ⚠️ THE WORDS COME FROM THE CATALOGUE, NOT FROM THE SOURCE. They used to be English strings
     * typed into `defaultActions`, beside a catalogue that already carried `actions.trash`,
     * `actions.restore` and `actions.purge` in every shipped language — written, and read by
     * nobody. A French back-office ended up with exactly three English words on it, all of them
     * on the menu that deletes files.
     */
    it('names its actions in the language it was given', () => {
        const french = mount(MhProvider, {
            props: { client: fakeClient(), locale: 'fr' },
            slots: { default: h(MhContextMenu, { open: true, selection: one, trashed: true }) },
            attachTo: document.body,
        })

        expect(labels(french, '[role="menuitem"]')).toEqual([
            'Restaurer',
            'Supprimer définitivement',
        ])
    })

    /**
     * ⚠️ EACH SIDE DROPS THE ONE ENTRY THAT MEANS NOTHING ON IT. Putting away what is already away
     * is refused by the operation itself; taking back what was never thrown away changes nothing.
     * Deleting for good is on both, because skipping the trash is a legitimate thing to ask for.
     */
    it('drops the entry that means nothing on each side', () => {
        expect(labels(menu(), '[role="menuitem"]')).toEqual([
            'Copy link',
            'Duplicate',
            'Download',
            'Move to trash',
        ])

        expect(labels(menu({ trashed: true }), '[role="menuitem"]')).toEqual([
            'Restore',
            'Delete permanently',
        ])
    })

    /**
     * ⚠️ DELETING FOR GOOD LEFT THE ORDINARY MENU, and that is the point of having a trash at
     * all. It used to sit one line under "Move to trash", both destructive, one of them final —
     * two entries a hurried hand cannot tell apart. Skipping the trash is still possible, from
     * the trash: one more click, in a place that says what it is.
     */
    it('offers no final deletion outside the trash', () => {
        expect(labels(menu(), '[role="menuitem"]')).not.toContain('Delete permanently')
        expect(labels(bar(), '[role="toolbar"] button')).not.toContain('Delete permanently')
    })

    /**
     * ⚠️ TWO ENTRIES ARE A PLACE, NOT A REQUEST, so they are offered only where there is one to
     * go to. A host rendering this menu on a screen of its own has no viewer and no prompt;
     * offering "Preview" there would give them an entry that does nothing, which is how a screen
     * teaches people to stop reading it.
     */
    it('offers what it was lent a surface for, and nothing more', () => {
        expect(labels(menu(), '[role="menuitem"]')).not.toContain('Preview')

        const lent = menu({ surfaces: { preview: () => {}, rename: () => {} } })

        /* ⚠️ AND AT THE TOP. An action supplied from outside lands at the end of the list, which
         * would put "Preview" underneath "Move to trash" — the destructive entry in the middle
         * of the ordinary ones. */
        expect(labels(lent, '[role="menuitem"]')).toEqual([
            'Preview',
            'Copy link',
            'Rename',
            'Duplicate',
            'Download',
            'Move to trash',
        ])
    })

    /** ⚠️ AND THE QUESTION ASKED BEFORE SOMETHING IRREVERSIBLE IS TRANSLATED TOO — it is the one
     * sentence somebody actually reads before agreeing to lose a file. */
    it('asks in that language as well', async () => {
        const french = mount(MhProvider, {
            props: { client: fakeClient(), locale: 'fr' },
            slots: { default: h(MhContextMenu, { open: true, selection: one, trashed: true }) },
            attachTo: document.body,
        })

        const purge = french.findAll('[role="menuitem"]')[1]

        await purge?.trigger('click')
        await nextTick()

        expect(french.find('dialog').text()).toContain('Supprimer définitivement ?')
    })

    it('offers the host own additions in both, too', () => {
        const extra: MhAction[] = [
            { id: 'publish', label: 'Publish', run: () => Promise.resolve() },
        ]

        expect(labels(bar({ actions: extra }), '[role="toolbar"] button')).toContain('Publish')
        expect(labels(menu({ actions: extra }), '[role="menuitem"]')).toContain('Publish')
    })

    /**
     * ⚠️ REPLACED BY IDENTIFIER, NOT APPENDED. A host rewording "Move to trash" would otherwise
     * be offering it twice, and one of the two would be the wording they were trying to remove.
     */
    it('lets a host replace one of ours rather than adding a second', () => {
        const extra: MhAction[] = [
            { id: 'trash', label: 'Archive', run: () => Promise.resolve() },
        ]

        const rendered = labels(bar({ actions: extra }), '[role="toolbar"] button')

        expect(rendered).toContain('Archive')
        expect(rendered).not.toContain('Move to trash')
    })

    /**
     * ⚠️ AN ACTION THAT DOES NOT APPLY IS NOT SHOWN. Offering it and failing teaches people that
     * the buttons lie, and they stop reading them.
     */
    it('hides what does not apply to the selection', () => {
        const empty: Selection = { media: [] }

        expect(labels(bar({ selection: empty }), '[role="toolbar"] button')).toEqual([])
    })
})

describe('asking before something irreversible', () => {
    it('asks before trashing', async () => {
        const wrapper = bar()

        /* ⚠️ FOUND BY ITS WORDING, NOT BY ITS POSITION. This bar was one entry long when the
         * bench was written; "Download" has since taken the first place, and a test that clicks
         * whatever happens to be at the top proves something about a different action every time
         * the list grows. */
        await trashing(wrapper)?.trigger('click')
        await nextTick()

        expect(wrapper.find('dialog').attributes('aria-label')).toBe('Move to the trash?')
    })

    /**
     * ⚠️ AND THE MENU ASKS THE SAME WAY. A context menu that deleted without asking, beside a bar
     * that asks, is a difference nobody documents and everybody discovers once.
     */
    it('asks the same way from the menu', async () => {
        const wrapper = menu()

        /* ⚠️ FOUND BY ITS WORDING, NOT BY ITS POSITION. This menu was two entries long when the
         * bench was written and is six now; the first one has been "Copy link" ever since, and
         * a test that clicks whatever happens to be at the top proves something about a
         * different action every time the list grows. */
        await wrapper
            .findAll('[role="menuitem"]')
            .filter((item) => item.text() === 'Move to trash')[0]
            ?.trigger('click')
        await nextTick()

        expect(wrapper.find('dialog').attributes('aria-label')).toBe('Move to the trash?')
    })

    it('does nothing at all until it is confirmed', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api })

        await trashing(wrapper)?.trigger('click')

        expect(api.calls).toHaveLength(0)
    })

    it('runs it once it is', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api })

        await trashing(wrapper)?.trigger('click')
        await nextTick()
        await wrapper.findAll('dialog button')[1]?.trigger('click')
        await nextTick()

        expect(api.calls.map((call) => call.method)).toContain('trash')
    })

    /** ⚠️ AN ACTION WITH NOTHING TO ASK RUNS STRAIGHT AWAY — a prompt on "Restore" is noise. */
    it('does not ask about an action that says nothing to ask', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api, trashed: true })

        const restore = wrapper
            .findAll('[role="toolbar"] button')
            .find((button) => button.text() === 'Restore')

        await restore?.trigger('click')
        await nextTick()

        expect(api.calls.map((call) => call.method)).toContain('restore')
    })
})

describe('the list on its own', () => {
    /**
     * ⚠️ A CALLER THAT SAYS NOTHING IS LOOKING AT A LIBRARY, and the default has to be one of the
     * two: "somewhere unspecified" would make every action either always shown or never. Our own
     * components always say where they are, so this branch belongs to a host writing its own
     * screen on the composable — which is a supported route, and therefore one to prove.
     */
    it('assumes the library when nobody says where it is', () => {
        const list = useMediaActionList(fakeClient(), () => one)

        expect(list.available.value.map((action) => action.id)).toEqual([
            'link',
            'duplicate',
            'download',
            'trash',
        ])
    })

    it('swaps putting away for taking back when told it is the trash', () => {
        const list = useMediaActionList(fakeClient(), () => one, undefined, () => ({
            trashed: true,
            picking: false,
        }))

        expect(list.available.value.map((action) => action.id)).toEqual(['restore', 'purge'])
    })
})

describe('the runner on its own', () => {
    /**
     * ⚠️ THE SELECTION IS READ WHEN THE ACTION RUNS, NOT WHEN IT WAS REQUESTED. Between the click
     * and the answer somebody can tick another file; capturing it early deletes what was selected
     * a dialog ago — which is precisely what a confirmation is supposed to prevent.
     */
    it('acts on what is selected at the moment it runs', async () => {
        let current: Selection = { media: ['m1'] }
        const seen: Selection[] = []

        const runner = useActionRunner(() => current)

        await runner.request({
            id: 'x',
            label: 'X',
            confirm: { title: 'Sure?' },
            run: (selection) => {
                seen.push(selection)

                return Promise.resolve()
            },
        })

        current = { media: ['m1', 'm2'] }
        await runner.confirm()

        expect(seen[0]?.media).toEqual(['m1', 'm2'])
    })

    it('forgets the question when it is dismissed', async () => {
        const runner = useActionRunner(() => one)

        await runner.request({ id: 'x', label: 'X', confirm: { title: '?' }, run: () => Promise.resolve() })
        runner.cancel()
        await runner.confirm()

        expect(runner.pending.value).toBeNull()
    })

    it('keeps a refusal rather than throwing it at a toolbar', async () => {
        const runner = useActionRunner(() => one)

        await runner.request({
            id: 'x',
            label: 'X',
            run: () => Promise.reject(new MediaHubError(403, 'forbidden', 'No.')),
        })

        expect(runner.error.value?.reason).toBe('forbidden')
        expect(runner.running.value).toBe(false)
    })

    /** ⚠️ AND SOMETHING THAT IS NOT A REFUSAL IS WRAPPED, not left to become an unhandled rejection. */
    it('wraps a failure that is not a refusal', async () => {
        const runner = useActionRunner(() => one)

        await runner.request({ id: 'x', label: 'X', run: () => Promise.reject(new Error('offline')) })

        expect(runner.error.value?.status).toBe(0)
    })
})

describe('what a deletion says it will take', () => {
    const withFolder: Selection = { folders: ['f1'] }

    async function ask(
        api: ReturnType<typeof fakeClient>,
        selection: Selection,
        wording: string,
        trashed = false,
    ) {
        const wrapper = mount(MhSelectionBar, {
            props: { selection, client: api, trashed },
            attachTo: document.body,
        })

        const action = wrapper
            .findAll('[role="toolbar"] button')
            .filter((button) => button.text() === wording)[0]

        await action?.trigger('click')
        await nextTick()
        await nextTick()

        return wrapper
    }

    /**
     * ⚠️ A FOLDER IS NEVER JUST A FOLDER. The server takes its whole subtree, nesting included, so
     * "move 1 folder to the trash" can mean four hundred files. Somebody who reads the count and
     * agrees has agreed to something they were told; somebody who reads "1 folder" has not.
     */
    it('names the files inside a folder before trashing it', async () => {
        const api = fakeClient()
        api.answerContents({ media: 412, folders: 9 })

        const wrapper = await ask(api, withFolder, 'Move to trash')

        expect(wrapper.find('dialog').text()).toContain('412 files inside')
    })

    /* ⚠️ FROM THE TRASH, which is the only place that offers it now. */
    it('says as much before deleting one for good', async () => {
        const api = fakeClient()
        api.answerContents({ media: 3, folders: 1 })

        const wrapper = await ask(api, withFolder, 'Delete permanently', true)

        expect(wrapper.find('dialog').text()).toContain('3 files inside')
    })

    /** ⚠️ AND ONE FILE IS NOT "1 files" — the rule for that belongs to the language. */
    it('counts one file in the singular', async () => {
        const api = fakeClient()
        api.answerContents({ media: 1, folders: 1 })

        const wrapper = await ask(api, withFolder, 'Move to trash')

        expect(wrapper.find('dialog').text()).toContain('1 file inside')
    })

    /** ⚠️ AN EMPTY BRANCH IS NOT WARNED ABOUT: a warning that fires every time stops being read. */
    it('says nothing about files when the folder holds none', async () => {
        const api = fakeClient()
        api.answerContents({ media: 0, folders: 1 })

        const wrapper = await ask(api, withFolder, 'Move to trash')

        expect(wrapper.find('dialog').text()).not.toContain('inside')
    })

    /** ⚠️ NOR IS THE SERVER ASKED WHEN NO FOLDER IS INVOLVED — nothing can be hidden under a file. */
    it('asks nothing of the server for a selection of files', async () => {
        const api = fakeClient()

        await ask(api, one, 'Move to trash')

        expect(api.calls.some((call) => call.method === 'contents')).toBe(false)
    })

    /**
     * ⚠️ A SERVER THAT CANNOT COUNT DOES NOT BLOCK THE DELETION. Failing to count is not a reason
     * to refuse: the question is put in its plain form, which is what it was in every case before
     * this existed. A confirmation that errored instead of asking would make an unreachable
     * server look like a broken button.
     */
    it('still asks the plain question when the count cannot be had', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(500, null, 'nope'))

        const wrapper = await ask(api, withFolder, 'Move to trash')

        expect(wrapper.find('dialog').text()).toContain('restored from the trash')
    })
})

describe('the menu as a menu', () => {
    it('announces itself as one', () => {
        expect(menu().find('[role="menu"]').exists()).toBe(true)
    })

    it('takes focus when it opens', async () => {
        const wrapper = menu({ open: false })

        await wrapper.setProps({ open: true })
        await nextTick()

        expect(document.activeElement).toBe(wrapper.findAll('[role="menuitem"]')[0]?.element)
    })

    /**
     * ⚠️ THE LISTENER ON THE DOCUMENT IS RELEASED WHEN THE MENU CLOSES. Left attached, every menu
     * that has ever opened keeps one, each holding its component alive through the closure — and
     * a screen opened and closed all day accumulates them silently. What gives it away is that a
     * closed menu goes on answering: a second click outside asks it to close all over again.
     *
     * ⚠️ CAUGHT BY MUTATION on 25/08/2026 — removing the release changed nothing anywhere else,
     * because a menu asked to close while already closed looks exactly like one that is closed.
     */
    it('stops listening to the document once it has closed', async () => {
        const wrapper = menu({ open: true })
        await nextTick()

        document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }))
        await nextTick()

        expect(wrapper.emitted('update:open')).toHaveLength(1)

        await wrapper.setProps({ open: false })
        await nextTick()

        document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }))
        await nextTick()

        expect(wrapper.emitted('update:open')).toHaveLength(1)
    })

    it('moves through its items with the arrows', async () => {
        const wrapper = menu()
        await nextTick()

        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'ArrowDown' })
        await nextTick()

        expect(document.activeElement).toBe(wrapper.findAll('[role="menuitem"]')[1]?.element)
    })

    /** ⚠️ A MENU WRAPS — reaching the last item from the first is what every other menu does. */
    it('wraps around', async () => {
        const wrapper = menu()
        await nextTick()

        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'ArrowUp' })
        await nextTick()

        const items = wrapper.findAll('[role="menuitem"]')

        expect(document.activeElement).toBe(items[items.length - 1]?.element)
    })

    it('closes on Escape', async () => {
        const wrapper = menu()

        await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' })

        expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
    })

    /** ⚠️ AND IT CLOSES BEFORE THE QUESTION APPEARS, so the menu is not left floating over it. */
    it('closes when an action is chosen', async () => {
        const wrapper = menu()

        await wrapper.findAll('[role="menuitem"]')[0]?.trigger('click')

        expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
    })
})
