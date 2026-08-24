import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import type { Selection } from '../client'
import { fakeClient } from '../vue/fake.test-utils'
import type { MhAction } from './actions'
import MhContextMenu from './MhContextMenu.vue'
import MhSelectionBar from './MhSelectionBar.vue'
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
        const inBar = labels(bar(), '[role="toolbar"] button').slice(0, -1)
        const inMenu = labels(menu(), '[role="menuitem"]')

        expect(inMenu).toEqual(inBar)
        expect(inMenu.length).toBeGreaterThan(0)
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

        await wrapper.findAll('[role="toolbar"] button')[0]?.trigger('click')
        await nextTick()

        expect(wrapper.find('dialog').attributes('aria-label')).toBe('Move to the trash?')
    })

    /**
     * ⚠️ AND THE MENU ASKS THE SAME WAY. A context menu that deleted without asking, beside a bar
     * that asks, is a difference nobody documents and everybody discovers once.
     */
    it('asks the same way from the menu', async () => {
        const wrapper = menu()

        await wrapper.findAll('[role="menuitem"]')[0]?.trigger('click')
        await nextTick()

        expect(wrapper.find('dialog').attributes('aria-label')).toBe('Move to the trash?')
    })

    it('does nothing at all until it is confirmed', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api })

        await wrapper.findAll('[role="toolbar"] button')[0]?.trigger('click')

        expect(api.calls).toHaveLength(0)
    })

    it('runs it once it is', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api })

        await wrapper.findAll('[role="toolbar"] button')[0]?.trigger('click')
        await nextTick()
        await wrapper.findAll('dialog button')[1]?.trigger('click')
        await nextTick()

        expect(api.calls.map((call) => call.method)).toContain('trash')
    })

    /** ⚠️ AN ACTION WITH NOTHING TO ASK RUNS STRAIGHT AWAY — a prompt on "Restore" is noise. */
    it('does not ask about an action that says nothing to ask', async () => {
        const api = fakeClient()
        const wrapper = bar({ client: api })

        const restore = wrapper
            .findAll('[role="toolbar"] button')
            .find((button) => button.text() === 'Restore')

        await restore?.trigger('click')
        await nextTick()

        expect(api.calls.map((call) => call.method)).toContain('restore')
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
