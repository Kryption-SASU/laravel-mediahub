import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { MediaHubError } from '../client'
import type { Media } from '../client'
import MhItemGrid from './MhItemGrid.vue'

function media(id: string, name = id): Media {
    return {
        id,
        name,
        file_name: `${id}.png`,
        extension: 'png',
        mime_type: 'image/png',
        type: 'image',
        size: 1024,
        width: 100,
        height: 100,
        duration: null,
        folder_id: null,
        custom_properties: {},
        url: `https://example.test/${id}`,
        download_url: `https://example.test/${id}/download`,
        thumbnail_url: null,
        trashed_at: null,
        created_at: null,
        updated_at: null,
    }
}

const four = [media('a'), media('b'), media('c'), media('d')]

function grid(props: Record<string, unknown> = {}) {
    return mount(MhItemGrid, { props: { media: four, ...props }, attachTo: document.body })
}

describe('the grid as a list of options', () => {
    it('announces itself as a listbox of options', () => {
        const wrapper = grid()

        expect(wrapper.find('[role="listbox"]').exists()).toBe(true)
        expect(wrapper.findAll('[role="option"]')).toHaveLength(4)
    })

    it('says whether more than one may be chosen', () => {
        expect(grid().find('[role="listbox"]').attributes('aria-multiselectable')).toBe('false')
        expect(grid({ multiple: true }).find('[role="listbox"]').attributes('aria-multiselectable')).toBe('true')
    })

    /** ⚠️ "3 OF 24" IS THE ONLY POSITION INFORMATION A SCREEN READER HAS in a grid of thumbnails. */
    it('gives each option its position', () => {
        const second = grid().findAll('[role="option"]')[1]

        expect(second?.attributes('aria-posinset')).toBe('2')
        expect(second?.attributes('aria-setsize')).toBe('4')
    })

    it('marks what is selected', () => {
        const options = grid({ selected: ['b'] }).findAll('[role="option"]')

        expect(options[1]?.attributes('aria-selected')).toBe('true')
        expect(options[0]?.attributes('aria-selected')).toBe('false')
    })
})

describe('choosing in the grid', () => {
    it('reports a single choice as a list of one', async () => {
        const wrapper = grid()

        await wrapper.findAll('[role="option"]')[2]?.trigger('click')

        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['c']])
    })

    /** ⚠️ A SINGLE-CHOICE GRID REPLACES, it does not accumulate. */
    it('replaces the previous choice when only one is allowed', async () => {
        const wrapper = grid({ selected: ['a'] })

        await wrapper.findAll('[role="option"]')[1]?.trigger('click')

        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['b']])
    })

    it('accumulates when several are allowed', async () => {
        const wrapper = grid({ multiple: true, selected: ['a'] })

        await wrapper.findAll('[role="option"]')[1]?.trigger('click')

        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['a', 'b']])
    })

    it('unchooses what was chosen', async () => {
        const wrapper = grid({ multiple: true, selected: ['a', 'b'] })

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')

        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['b']])
    })

    /**
     * ⚠️ THE GRID HOLDS NO SELECTION OF ITS OWN. A second copy of that state is how a screen ends
     * up showing three items ticked while the form posts two.
     */
    it('does not tick anything by itself', async () => {
        const wrapper = grid()

        await wrapper.findAll('[role="option"]')[0]?.trigger('click')

        expect(wrapper.findAll('[aria-selected="true"]')).toHaveLength(0)
    })

    it('opens an item on a double click', async () => {
        const wrapper = grid()

        await wrapper.findAll('[role="option"]')[1]?.trigger('dblclick')

        expect(wrapper.emitted('activate')?.[0]).toEqual([four[1]])
    })
})

describe('moving through the grid with a keyboard', () => {
    /**
     * ⚠️ ONE TAB STOP FOR THE WHOLE GRID. Twenty-four items each taking a stop means a keyboard
     * user presses Tab twenty-four times to get past a picker — and every screen embedding one
     * becomes slower to leave than to use.
     */
    it('offers a single tab stop', () => {
        const tabbable = grid()
            .findAll('[role="option"]')
            .filter((option) => option.attributes('tabindex') === '0')

        expect(tabbable).toHaveLength(1)
    })

    it('moves the stop with the arrows', async () => {
        const wrapper = grid()

        await wrapper.find('[role="listbox"]').trigger('keydown', { key: 'ArrowRight' })
        await nextTick()

        expect(wrapper.findAll('[role="option"]')[1]?.attributes('tabindex')).toBe('0')
    })

    /**
     * ⚠️ AND THE CARET FOLLOWS IT. Changing which item is tabbable does not move focus: without
     * that, the arrows would repaint an outline while the browser's focus stayed behind, and the
     * next Enter would choose the wrong file.
     */
    it('takes focus with it', async () => {
        const wrapper = grid()

        await wrapper.find('[role="listbox"]').trigger('keydown', { key: 'ArrowRight' })
        await nextTick()

        expect(document.activeElement).toBe(wrapper.findAll('[role="option"]')[1]?.element)
    })

    it('goes to the ends with Home and End', async () => {
        const wrapper = grid()
        const listbox = wrapper.find('[role="listbox"]')

        await listbox.trigger('keydown', { key: 'End' })
        await nextTick()
        expect(wrapper.findAll('[role="option"]')[3]?.attributes('tabindex')).toBe('0')

        await listbox.trigger('keydown', { key: 'Home' })
        await nextTick()
        expect(wrapper.findAll('[role="option"]')[0]?.attributes('tabindex')).toBe('0')
    })

    /** ⚠️ IT STOPS AT THE ENDS. Wrapping around reads as the list having been shuffled. */
    it('does not wrap around', async () => {
        const wrapper = grid()

        await wrapper.find('[role="listbox"]').trigger('keydown', { key: 'ArrowLeft' })
        await nextTick()

        expect(wrapper.findAll('[role="option"]')[0]?.attributes('tabindex')).toBe('0')
    })

    it('moves by a row when it was told the width', async () => {
        const wrapper = grid({ columns: 2 })

        await wrapper.find('[role="listbox"]').trigger('keydown', { key: 'ArrowDown' })
        await nextTick()

        expect(wrapper.findAll('[role="option"]')[2]?.attributes('tabindex')).toBe('0')
    })

    it('chooses with the space bar and opens with Enter', async () => {
        const wrapper = grid()
        const listbox = wrapper.find('[role="listbox"]')

        await listbox.trigger('keydown', { key: ' ' })
        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['a']])

        await listbox.trigger('keydown', { key: 'Enter' })
        expect(wrapper.emitted('activate')?.[0]).toEqual([four[0]])
    })

    /**
     * ⚠️ A CURSOR PAST THE END LEAVES NO TAB STOP AT ALL, and the grid becomes unreachable by
     * keyboard without anything on screen saying so. Paging to a shorter last page does exactly
     * that, and so does a search that narrows the results.
     */
    it('pulls the stop back when the list gets shorter', async () => {
        const wrapper = grid()

        await wrapper.find('[role="listbox"]').trigger('keydown', { key: 'End' })
        await wrapper.setProps({ media: [four[0], four[1]] as Media[] })
        await nextTick()

        const tabbable = wrapper
            .findAll('[role="option"]')
            .filter((option) => option.attributes('tabindex') === '0')

        expect(tabbable).toHaveLength(1)
    })
})

describe('what the grid shows instead of items', () => {
    it('shows the wait rather than an empty grid', () => {
        const wrapper = grid({ loading: true, media: [] })

        expect(wrapper.find('[role="status"]').exists()).toBe(true)
    })

    it('shows a refusal rather than nothing', () => {
        const wrapper = grid({ media: [], error: new MediaHubError(403, 'forbidden', 'No.') })

        expect(wrapper.find('[role="alert"]').text()).toContain('No.')
    })

    /**
     * ⚠️ AN EMPTY LIST IS NOT A FAILURE, AND NOT A WAIT EITHER. Showing the loading state for an
     * empty folder leaves somebody watching placeholders pulse for a page that already arrived.
     */
    it('lets the caller say what empty means', () => {
        const wrapper = mount(MhItemGrid, {
            props: { media: [] },
            slots: { empty: '<p>Nothing in this folder.</p>' },
        })

        expect(wrapper.text()).toContain('Nothing in this folder.')
        expect(wrapper.find('[role="status"]').exists()).toBe(false)
    })
})
