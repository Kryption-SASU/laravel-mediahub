import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MhPager from './MhPager.vue'

function pager(props: Record<string, unknown> = {}) {
    return mount(MhPager, {
        props: { page: 1, pages: 5, total: 220, ...props },
        attachTo: document.body,
    })
}

function numbers(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper
        .findAll('button')
        .map((button) => button.text())
        .filter((text) => /^\d+$/.test(text))
}

describe('where you are in a listing', () => {
    /**
     * ⚠️ HOW MUCH THERE IS, NOT ONLY WHERE YOU ARE. "Page 2 of 7" tells somebody they can keep
     * clicking; "312 items" tells them to search instead, which in a media library is usually the
     * better move.
     */
    it('says how much the listing holds', () => {
        expect(pager({ total: 312 }).text()).toContain('312 items')
    })

    /** ⚠️ AND ONE ITEM IS NOT "1 items" — the rule for that belongs to the language. */
    it('counts one item in the singular', () => {
        expect(pager({ total: 1, pages: 1 }).text()).toContain('1 item')
    })

    /**
     * ⚠️ THE COUNT SHOWS EVEN WITH NOTHING TO PAGE THROUGH. How much a folder holds is worth
     * knowing whether or not it spills over — the controls are what disappear, not the fact.
     */
    it('keeps the count but drops the controls on a single page', () => {
        const wrapper = pager({ pages: 1, total: 12 })

        expect(wrapper.text()).toContain('12 items')
        expect(numbers(wrapper)).toEqual([])
    })

    it('says which page is being looked at', () => {
        expect(pager({ page: 3, pages: 7 }).text()).toContain('Page 3 of 7')
    })
})

describe('the numbers worth drawing', () => {
    /**
     * ⚠️ A THOUSAND PAGES CANNOT ALL BE BUTTONS. The first and the last are always there — they
     * are the two places people aim for — with a window around the current one and a gap between.
     * Rendering every number turns a control into a wall.
     */
    it('keeps the ends, a window, and a gap for the rest', () => {
        const wrapper = pager({ page: 20, pages: 40 })

        expect(numbers(wrapper)).toEqual(['1', '18', '19', '20', '21', '22', '40'])
        expect(wrapper.text()).toContain('…')
    })

    /** ⚠️ NO GAP WHERE NOTHING IS MISSING — an ellipsis standing for one page is a lie about two. */
    it('draws no gap when the numbers run on', () => {
        const wrapper = pager({ page: 3, pages: 5 })

        expect(numbers(wrapper)).toEqual(['1', '2', '3', '4', '5'])
        expect(wrapper.text()).not.toContain('…')
    })

    it('marks the page being looked at', () => {
        const current = pager({ page: 3, pages: 5 })
            .findAll('button')
            .filter((button) => button.attributes('aria-current') === 'page')

        expect(current).toHaveLength(1)
        expect(current[0]?.text()).toBe('3')
    })
})

describe('going elsewhere', () => {
    it('asks for the page that was clicked', async () => {
        const wrapper = pager({ page: 1, pages: 5 })

        /* ⚠️ THE LAST PAGE, WHICH IS ALWAYS DRAWN. From page 1 the window covers 1 to 3, so a
         * "4" is not on screen at all — clicking one that is not there proves nothing. */
        await wrapper.findAll('button').filter((b) => b.text() === '5')[0]?.trigger('click')

        expect(wrapper.emitted('go')?.[0]).toEqual([5])
    })

    /**
     * ⚠️ NOTHING IS ASKED FOR TWICE. Clicking the page already shown would spend a round trip to
     * redraw what is on screen, and blank the grid while it waited.
     */
    it('asks for nothing when the page is already the one shown', async () => {
        const wrapper = pager({ page: 3, pages: 5 })

        await wrapper.findAll('button').filter((b) => b.text() === '3')[0]?.trigger('click')

        expect(wrapper.emitted('go')).toBeUndefined()
    })

    it('steps forward and back', async () => {
        const wrapper = pager({ page: 3, pages: 5 })
        const steps = wrapper.findAll('button[aria-label]')

        await steps[0]?.trigger('click')
        await steps[1]?.trigger('click')

        expect(wrapper.emitted('go')).toEqual([[2], [4]])
    })

    /** ⚠️ AND THE STEPS ARE REFUSED AT THE ENDS, rather than asking for page 0 or page 8. */
    it('offers no step past either end', () => {
        const first = pager({ page: 1, pages: 5 }).findAll('button[aria-label]')
        const last = pager({ page: 5, pages: 5 }).findAll('button[aria-label]')

        expect(first[0]?.attributes('disabled')).toBeDefined()
        expect(first[1]?.attributes('disabled')).toBeUndefined()

        expect(last[0]?.attributes('disabled')).toBeUndefined()
        expect(last[1]?.attributes('disabled')).toBeDefined()
    })
})
