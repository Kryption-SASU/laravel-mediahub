import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import MhConfirmDialog from './MhConfirmDialog.vue'

async function open(props: Record<string, unknown> = {}) {
    const wrapper = mount(MhConfirmDialog, {
        props: { open: true, title: 'Delete for good?', ...props },
        attachTo: document.body,
    })

    // The dialog is shown after the render, never during it.
    await nextTick()

    return wrapper
}

describe('asking before something irreversible', () => {
    it('opens as a modal rather than as a plain element', async () => {
        const wrapper = await open()
        const dialog = wrapper.find('dialog').element as HTMLDialogElement

        expect(dialog.open).toBe(true)
    })

    it('names itself for assistive technology', async () => {
        expect((await open()).find('dialog').attributes('aria-label')).toBe('Delete for good?')
    })

    it('answers yes when confirmed', async () => {
        const wrapper = await open({ confirmLabel: 'Delete' })

        await wrapper.findAll('button')[1]?.trigger('click')

        expect(wrapper.emitted('confirm')).toHaveLength(1)
        expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
    })

    /**
     * ⚠️ CONFIRMING MUST NOT ALSO CANCEL. Closing the dialog fires `close` whoever asked for it,
     * so the naive wiring emits `confirm` and then `cancel` for a single click — and a caller
     * undoing its optimistic update on the second event puts the deleted rows back on screen.
     */
    it('does not also report a cancellation', async () => {
        const wrapper = await open()

        await wrapper.findAll('button')[1]?.trigger('click')
        await wrapper.setProps({ open: false })

        expect(wrapper.emitted('cancel')).toBeUndefined()
    })

    /**
     * ⚠️ THE ANSWER MUST NOT DEPEND ON WHEN THE DOM CATCHES UP. Showing the element is deferred
     * to after the render; tying the "already answered" flag to that moment means an answer
     * given in between is wiped by the very effect that opens the dialog, and the close that
     * follows is then reported as a cancellation. Here the click happens before that effect
     * runs — which is precisely the ordering the flag must be immune to.
     */
    it('holds its answer even if it is given before the dialog is shown', async () => {
        const wrapper = mount(MhConfirmDialog, {
            props: { open: true, title: 'Delete for good?' },
            attachTo: document.body,
        })

        await wrapper.findAll('button')[1]?.trigger('click')
        await wrapper.setProps({ open: false })

        expect(wrapper.emitted('confirm')).toHaveLength(1)
        expect(wrapper.emitted('cancel')).toBeUndefined()
    })

    it('answers no when cancelled', async () => {
        const wrapper = await open()

        await wrapper.findAll('button')[0]?.trigger('click')

        expect(wrapper.emitted('cancel')).toHaveLength(1)
        expect(wrapper.emitted('confirm')).toBeUndefined()
    })

    /**
     * ⚠️ ESCAPE MEANS NO. The browser dismisses a modal on Escape whether or not anyone wired it;
     * a dialog that treated its own dismissal as an answer would delete files on a keystroke
     * people press to get out of things.
     */
    it('reads a dismissal as a refusal', async () => {
        const wrapper = await open()

        await wrapper.find('dialog').trigger('cancel')

        expect(wrapper.emitted('cancel')).toHaveLength(1)
        expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
    })

    /**
     * ⚠️ AND THE HOST'S STATE HAS TO FOLLOW. Letting the browser close it natively would leave
     * `open` true on their side, and the next attempt to open the dialog would do nothing at
     * all — a confirmation that works exactly once.
     */
    it('tells the host it closed, so it can be opened again', async () => {
        const wrapper = await open()

        await wrapper.find('dialog').trigger('cancel')
        await wrapper.setProps({ open: false })
        await wrapper.setProps({ open: true })

        expect((wrapper.find('dialog').element as HTMLDialogElement).open).toBe(true)
    })

    it('closes when the host says so', async () => {
        const wrapper = await open()

        await wrapper.setProps({ open: false })

        expect((wrapper.find('dialog').element as HTMLDialogElement).open).toBe(false)
    })

    /** ⚠️ THE LOOK FOLLOWS THE CONSEQUENCE, not the wording on the button. */
    it('marks a destructive answer differently', async () => {
        const plain = (await open()).findAll('button')[1]?.classes().join(' ')
        const destructive = (await open({ destructive: true })).findAll('button')[1]?.classes().join(' ')

        expect(destructive).not.toBe(plain)
    })

    it('stays shut until it is asked to open', () => {
        const wrapper = mount(MhConfirmDialog, {
            props: { open: false, title: 'Delete?' },
            attachTo: document.body,
        })

        expect((wrapper.find('dialog').element as HTMLDialogElement).open).toBe(false)
    })
})
