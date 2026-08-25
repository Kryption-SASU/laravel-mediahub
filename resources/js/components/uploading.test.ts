import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { UploadItem } from '../client'
import MhDropzone from './MhDropzone.vue'
import MhQuotaMeter from './MhQuotaMeter.vue'
import MhUploadButton from './MhUploadButton.vue'
import MhUploadQueue from './MhUploadQueue.vue'

function file(name: string): File {
    return new File(['bytes'], name, { type: 'image/png' })
}

function item(over: Partial<UploadItem> = {}): UploadItem {
    return {
        id: 'u1',
        file: file('photo.png'),
        folder: null,
        status: 'uploading',
        progress: 0.4,
        media: null,
        reason: null,
        message: null,
        ...over,
    }
}

function drop(files: File[]): { dataTransfer: { files: File[] } } {
    return { dataTransfer: { files } }
}

describe('dropping files', () => {
    /**
     * ⚠️ IT WRAPS THE LISTING RATHER THAN SITTING ABOVE IT. A dashed rectangle parked over the
     * grid accepted a drop on its own few hundred pixels and nowhere else — so a file let go
     * over the files, which is where the hand goes, was opened by the browser and took the page
     * with it.
     */
    it('makes a drop target of whatever it wraps', () => {
        const wrapper = mount(MhDropzone, { slots: { default: '<p id="listing">files</p>' } })

        expect(wrapper.find('#listing').exists()).toBe(true)
    })

    /**
     * ⚠️ AND IT NO LONGER CARRIES THE FILE INPUT. The keyboard route is a primary control, not a
     * detail of a drag affordance: it lives in `MhUploadButton` now. Two inputs on one screen
     * would be two answers to "how do I add a file", one of them buried in the listing.
     */
    it('leaves the keyboard route to the upload button', () => {
        expect(mount(MhDropzone).find('input[type="file"]').exists()).toBe(false)
    })

    /**
     * ⚠️ THE INVITATION IS SHOWN WHILE SOMETHING IS HELD, AND ONLY THEN. At rest it would be a
     * banner across the files, which is what the dashed rectangle already was.
     */
    it('says what a drop will do, only while something is being held', async () => {
        const wrapper = mount(MhDropzone, { props: { label: 'Drop to upload' } })

        expect(wrapper.text()).not.toContain('Drop to upload')

        await wrapper.trigger('dragenter')

        expect(wrapper.text()).toContain('Drop to upload')
    })

    it('reports what was dropped', async () => {
        const wrapper = mount(MhDropzone)

        await wrapper.trigger('drop', drop([file('a.png'), file('b.png')]))

        expect(wrapper.emitted('files')?.[0]?.[0]).toHaveLength(2)
    })

    /**
     * ⚠️ AN EMPTY DROP IS NOT AN UPLOAD. Dragging a piece of text, or a link, lands here with no
     * files at all — reporting it would open a queue with nothing in it.
     */
    it('says nothing when nothing was dropped', async () => {
        const wrapper = mount(MhDropzone)

        await wrapper.trigger('drop', drop([]))

        expect(wrapper.emitted('files')).toBeUndefined()
    })

    it('refuses a drop while disabled', async () => {
        const wrapper = mount(MhDropzone, { props: { disabled: true } })

        await wrapper.trigger('drop', drop([file('a.png')]))

        expect(wrapper.emitted('files')).toBeUndefined()
    })

    /**
     * ⚠️ THE HIGHLIGHT IS COUNTED, NOT TOGGLED. `dragenter` and `dragleave` fire for every child
     * the pointer crosses, so a boolean blinks off the moment somebody drags over the label
     * inside the zone — while they are still holding the file over it.
     */
    it('stays highlighted while the pointer crosses its own children', async () => {
        const wrapper = mount(MhDropzone)
        const before = wrapper.classes().join(' ')

        await wrapper.trigger('dragenter')
        const entered = wrapper.classes().join(' ')

        await wrapper.trigger('dragenter')
        await wrapper.trigger('dragleave')

        expect(entered).not.toBe(before)
        expect(wrapper.classes().join(' ')).toBe(entered)
    })

    it('stops being highlighted once the pointer really leaves', async () => {
        const wrapper = mount(MhDropzone)
        const before = wrapper.classes().join(' ')

        await wrapper.trigger('dragenter')
        await wrapper.trigger('dragleave')

        expect(wrapper.classes().join(' ')).toBe(before)
    })
})

describe('the button that opens a file picker', () => {
    function chosen(wrapper: ReturnType<typeof mount>, files: File[]): Promise<void> {
        const input = wrapper.find('input[type="file"]')

        /* ⚠️ `files` IS READ-ONLY ON A REAL INPUT, and there is no way to fill it from a script.
         * Defining it is the only way to exercise the handler at all; the alternative is to
         * leave the one route a keyboard user has with no test behind it. */
        Object.defineProperty(input.element, 'files', { value: files, configurable: true })

        /* ⚠️ AND `value` HAS TO BE PUT BACK TOO, or the assertion that it was emptied proves
         * nothing: a file input starts empty, so "it is empty afterwards" is true whether or not
         * anybody cleared it. Caught by mutation on 25/08/2026 — the check was passing against
         * code that had stopped clearing anything. */
        Object.defineProperty(input.element, 'value', {
            value: files.length > 0 ? 'C:\\fakepath\\' + files[0]?.name : '',
            writable: true,
            configurable: true,
        })

        return input.trigger('change')
    }

    /**
     * ⚠️ THE FILE INPUT IS NOT A FALLBACK, IT IS THE ONLY ROUTE FOR SOME PEOPLE. Dragging cannot
     * be done from a keyboard, is awkward with a screen reader and is impossible on most touch
     * devices — so it stays a real, labelled control rather than something hidden behind a click
     * handler on a `display: none` element.
     */
    it('offers a real, labelled file input', () => {
        const wrapper = mount(MhUploadButton)
        const input = wrapper.find('input[type="file"]')

        expect(input.exists()).toBe(true)
        expect(input.attributes('multiple')).toBeDefined()
        expect(wrapper.find('label').attributes('for')).toBe(input.attributes('id'))
    })

    it('reports what was chosen', async () => {
        const wrapper = mount(MhUploadButton)

        await chosen(wrapper, [file('a.png'), file('b.png')])

        expect(wrapper.emitted('files')?.[0]?.[0]).toHaveLength(2)
    })

    /**
     * ⚠️ THE INPUT IS EMPTIED AFTERWARDS. Choosing the same file twice in a row fires no `change`
     * event otherwise — the value has not changed — and the second attempt does nothing, which
     * reads as the button being broken.
     */
    it('empties itself so the same file can be chosen again', async () => {
        const wrapper = mount(MhUploadButton)

        await chosen(wrapper, [file('a.png')])

        expect((wrapper.find('input[type="file"]').element as HTMLInputElement).value).toBe('')
    })

    /** ⚠️ A DISMISSED PICKER IS NOT AN UPLOAD: it fires `change` with nothing in it. */
    it('says nothing when the picker was dismissed', async () => {
        const wrapper = mount(MhUploadButton)

        await chosen(wrapper, [])

        expect(wrapper.emitted('files')).toBeUndefined()
    })
})

describe('the queue on screen', () => {
    it('shows nothing at all when nothing is queued', () => {
        expect(mount(MhUploadQueue, { props: { items: [] } }).find('section').exists()).toBe(false)
    })

    /**
     * ⚠️ EACH FILE HAS ITS OWN FATE. One request per file means one can be refused while the rest
     * land; a single bar for the batch reports the whole thing as failed, and somebody uploads
     * nineteen files again to recover one.
     */
    it('gives each file its own row and its own progress', () => {
        const wrapper = mount(MhUploadQueue, {
            props: { items: [item({ id: 'a' }), item({ id: 'b', file: file('other.png') })] },
        })

        expect(wrapper.findAll('li')).toHaveLength(2)
        expect(wrapper.findAll('progress')).toHaveLength(2)
    })

    /**
     * ⚠️ AN UPLOAD WHOSE TOTAL IS UNKNOWN IS INDETERMINATE, NOT AT ZERO. A bar pinned at the left
     * end says "nothing has happened", which is exactly wrong while bytes are going out.
     */
    it('leaves the bar indeterminate while nothing can be measured', () => {
        const wrapper = mount(MhUploadQueue, { props: { items: [item({ progress: 0 })] } })

        expect(wrapper.find('progress').attributes('value')).toBeUndefined()
    })

    it('counts what is finished rather than announcing every percent', () => {
        const wrapper = mount(MhUploadQueue, {
            props: { items: [item({ id: 'a', status: 'done' }), item({ id: 'b' })] },
        })

        expect(wrapper.find('[role="status"]').text()).toBe('1 / 2')
    })

    /** ⚠️ THE REASON REACHES THE SLOT — only the host knows what "too_large" should offer. */
    it('shows what went wrong, and hands over the stable key', () => {
        const wrapper = mount(MhUploadQueue, {
            props: {
                items: [item({ status: 'failed', reason: 'too_large', message: 'That file is too big.' })],
            },
            slots: { error: '<template #error="{ reason }">key:{{ reason }}</template>' },
        })

        expect(wrapper.text()).toContain('key:too_large')
    })

    it('offers to stop what is running and to retry what failed', async () => {
        const running = mount(MhUploadQueue, { props: { items: [item()] } })
        await running.find('li button').trigger('click')

        expect(running.emitted('abort')?.[0]).toEqual(['u1'])

        const failed = mount(MhUploadQueue, { props: { items: [item({ status: 'failed' })] } })
        await failed.find('li button').trigger('click')

        expect(failed.emitted('retry')?.[0]).toEqual(['u1'])
    })

    /** ⚠️ AND AN ABORTED UPLOAD CAN BE RETRIED — stopping something is not deciding against it. */
    it('offers to retry what was stopped', () => {
        const wrapper = mount(MhUploadQueue, { props: { items: [item({ status: 'aborted' })] } })

        expect(wrapper.find('li button').text()).toBe('Try again')
    })

    it('offers to clear only once something has finished', () => {
        const running = mount(MhUploadQueue, { props: { items: [item()] } })
        const done = mount(MhUploadQueue, { props: { items: [item({ status: 'done' })] } })

        expect(running.text()).not.toContain('Clear finished')
        expect(done.text()).toContain('Clear finished')
    })

    /** ⚠️ EVERY BUTTON NAMES ITS FILE — six "Stop" buttons say nothing about which one stops. */
    it('names the file each button acts on', () => {
        const wrapper = mount(MhUploadQueue, { props: { items: [item()] } })

        expect(wrapper.find('li button').attributes('aria-label')).toBe('Stop: photo.png')
    })
})

describe('the quota', () => {
    it('shows nothing before anything was read', () => {
        expect(mount(MhQuotaMeter, { props: { quota: null } }).find('div').exists()).toBe(false)
    })

    it('draws the gauge where there is a limit', () => {
        const wrapper = mount(MhQuotaMeter, {
            props: { quota: { limit: 1000, used: 250, remaining: 750, unlimited: false } },
        })

        expect(wrapper.find('meter').attributes('value')).toBe('25')
    })

    /**
     * ⚠️ NO GAUGE AT ALL WHERE THERE IS NO LIMIT. Zero would read as "empty" and a hundred as
     * "full", while the truth is that the question does not apply.
     */
    it('draws no gauge where there is no limit', () => {
        const wrapper = mount(MhQuotaMeter, {
            props: { quota: { limit: null, used: 250, remaining: null, unlimited: true } },
        })

        expect(wrapper.find('meter').exists()).toBe(false)
        expect(wrapper.text()).toContain('Unlimited')
    })

    /** ⚠️ AND IT NEVER GOES PAST THE END OF ITS TRACK, which is when it most needs to be read. */
    it('caps the gauge when usage passed the limit', () => {
        const wrapper = mount(MhQuotaMeter, {
            props: { quota: { limit: 100, used: 250, remaining: 0, unlimited: false } },
        })

        expect(wrapper.find('meter').attributes('value')).toBe('100')
    })

    /** ⚠️ SIZES ARE SPELLED OUT — "1073741824" is a number people have to convert every time. */
    it('says sizes in units somebody reads', () => {
        const wrapper = mount(MhQuotaMeter, {
            props: { quota: { limit: 1073741824, used: 536870912, remaining: 536870912, unlimited: false } },
        })

        expect(wrapper.text()).toContain('512 MB')
        expect(wrapper.text()).toContain('1 GB')
    })

    it('survives a limit of zero rather than dividing by it', () => {
        const wrapper = mount(MhQuotaMeter, {
            props: { quota: { limit: 0, used: 0, remaining: 0, unlimited: false } },
        })

        expect(wrapper.find('meter').exists()).toBe(false)
    })
})
