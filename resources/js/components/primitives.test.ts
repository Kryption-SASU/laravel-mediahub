import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { Media } from '../client'
import MhEmptyState from './MhEmptyState.vue'
import MhErrorState from './MhErrorState.vue'
import MhSkeleton from './MhSkeleton.vue'
import MhThumbnail from './MhThumbnail.vue'
import { MediaHubError } from '../client'

function media(over: Partial<Media> = {}): Media {
    return {
        id: 'm1',
        name: 'Annual report',
        file_name: 'annual-report.pdf',
        extension: 'pdf',
        mime_type: 'application/pdf',
        type: 'document',
        size: 1024,
        width: null,
        height: null,
        duration: null,
        folder_id: null,
        custom_properties: {},
        url: 'https://example.test/m1/file',
        download_url: 'https://example.test/m1/download',
        thumbnail_url: null,
        can_draw: false,
        trashed_at: null,
        created_at: null,
        updated_at: null,
        ...over,
    }
}

describe('the thumbnail', () => {
    it('shows the derivative when there is one', () => {
        const wrapper = mount(MhThumbnail, {
            props: { media: media({ thumbnail_url: 'https://example.test/thumb.webp' }) },
        })

        expect(wrapper.find('img').attributes('src')).toBe('https://example.test/thumb.webp')
    })

    /**
     * ⚠️ WITHOUT AN IMAGE LIBRARY THERE IS NEVER A DERIVATIVE. The package installs and works
     * with neither GD nor Imagick, and `thumbnail_url` is then null forever: a component
     * insisting on it would render a library in which no picture is ever visible.
     */
    it('falls back to the original for an image with no derivative', () => {
        const wrapper = mount(MhThumbnail, {
            props: { media: media({ type: 'image', url: 'https://example.test/photo.jpg' }) },
        })

        expect(wrapper.find('img').attributes('src')).toBe('https://example.test/photo.jpg')
    })

    /** ⚠️ AND NEVER FOR ANYTHING ELSE — a 40 MB video in an `<img>` is a download, not a preview. */
    it('does not put a document in an image tag', () => {
        const wrapper = mount(MhThumbnail, { props: { media: media() } })

        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.text()).toContain('pdf')
    })

    /**
     * ⚠️ A BROKEN PICTURE GLYPH SAYS NOTHING AND LOOKS LIKE A BUG. An expired signature, a file
     * removed behind the library's back, a derivative not built yet: all of them land here.
     */
    it('replaces a picture that failed to load', async () => {
        const wrapper = mount(MhThumbnail, {
            props: { media: media({ thumbnail_url: 'https://example.test/gone.webp' }) },
        })

        await wrapper.find('img').trigger('error')

        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.find('[role="img"]').exists()).toBe(true)
    })

    /**
     * ⚠️ ONE FAILURE MUST NOT POISON THE SLOT. A grid recycles component instances as it pages;
     * keeping the failed flag would blank every item that later passes through this instance.
     */
    it('tries again when it is given another media', async () => {
        const wrapper = mount(MhThumbnail, {
            props: { media: media({ thumbnail_url: 'https://example.test/gone.webp' }) },
        })

        await wrapper.find('img').trigger('error')
        await wrapper.setProps({ media: media({ id: 'm2', thumbnail_url: 'https://example.test/ok.webp' }) })

        expect(wrapper.find('img').attributes('src')).toBe('https://example.test/ok.webp')
    })

    it('describes the picture with the declared alternative text', () => {
        const wrapper = mount(MhThumbnail, {
            props: {
                media: media({
                    thumbnail_url: 'https://example.test/t.webp',
                    custom_properties: { alt: 'The 2026 report cover' },
                }),
            },
        })

        expect(wrapper.find('img').attributes('alt')).toBe('The 2026 report cover')
    })

    /**
     * ⚠️ `null` IS DECORATIVE, AND IT IS NOT THE SAME AS ABSENT. Beside a visible file name, a
     * thumbnail repeating that name makes a screen reader announce everything twice.
     */
    it('can be made decorative', () => {
        const wrapper = mount(MhThumbnail, {
            props: { media: media({ thumbnail_url: 'https://example.test/t.webp' }), alt: null },
        })

        expect(wrapper.find('img').attributes('alt')).toBe('')
    })

    it('takes a number as pixels', () => {
        const wrapper = mount(MhThumbnail, { props: { media: media(), size: 64 } })

        expect(wrapper.attributes('style')).toContain('width: 64px')
    })

    /**
     * ⚠️ A FILE THAT CANNOT BE PICTURED IS DRAWN, NOT SPELLED OUT. The tile used to print the
     * first four letters of the extension, falling back to the kind — so a video with no
     * extension rendered a box reading "VIDE", which is a French word, and the wrong one.
     */
    it('draws the kind when there is nothing to show', () => {
        const wrapper = mount(MhThumbnail, { props: { media: media({ type: 'video' }) } })

        expect(wrapper.find('svg').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('VIDE')
    })

    /** ⚠️ AND EACH KIND IS ITS OWN DRAWING: one glyph for six kinds says nothing at a glance. */
    it('draws a different thing for a different kind', () => {
        const drawing = (kind: Media['type']): string =>
            mount(MhThumbnail, { props: { media: media({ type: kind }) } }).find('svg').html()

        expect(drawing('video')).not.toBe(drawing('audio'))
        expect(drawing('document')).not.toBe(drawing('external'))
    })

    /**
     * ⚠️ THE EXTENSION STAYS UNDER THE GLYPH, BECAUSE SIX KINDS DO NOT ANSWER EVERYTHING. "Is
     * this a PDF or a Word document" is the question actually asked of a document tile.
     */
    it('keeps the extension beside the drawing', () => {
        const wrapper = mount(MhThumbnail, { props: { media: media({ extension: 'pdf' }) } })

        expect(wrapper.text()).toContain('pdf')
    })

    /** ⚠️ ABSENT, NOTHING IS PRINTED: an empty caption reserves a line for a word never coming. */
    it('prints no caption for a file without an extension', () => {
        const wrapper = mount(MhThumbnail, { props: { media: media({ extension: null }) } })

        expect(wrapper.text()).toBe('')
    })
})

describe('the empty state', () => {
    it('says which emptiness it is', () => {
        const wrapper = mount(MhEmptyState, {
            props: { title: 'No results', description: 'Nothing matches “invoice”.' },
        })

        expect(wrapper.text()).toContain('No results')
        expect(wrapper.text()).toContain('invoice')
    })

    /** ⚠️ AN EMPTY ACTIONS ROW STILL TAKES SPACE, and the gap reads as something that failed. */
    it('renders no actions row when nobody filled it', () => {
        const wrapper = mount(MhEmptyState, { props: { title: 'Empty' } })

        expect(wrapper.findAll('div')).toHaveLength(1)
    })

    it('renders one when somebody did', () => {
        const wrapper = mount(MhEmptyState, {
            props: { title: 'Empty' },
            slots: { actions: '<button>Upload</button>' },
        })

        expect(wrapper.find('button').exists()).toBe(true)
    })
})

describe('the skeleton', () => {
    it('draws the number of placeholders it was asked for', () => {
        const wrapper = mount(MhSkeleton, { props: { count: 3 } })

        expect(wrapper.findAll('span')).toHaveLength(3)
    })

    /**
     * ⚠️ THE WAIT IS ANNOUNCED ONCE, THE BOXES NOT AT ALL. Eight empty nodes read one after
     * another tell somebody only that their screen reader is stuck.
     */
    it('announces the wait rather than the boxes', () => {
        const wrapper = mount(MhSkeleton, { props: { count: 2, label: 'Loading media' } })

        expect(wrapper.attributes('role')).toBe('status')
        expect(wrapper.attributes('aria-label')).toBe('Loading media')
        expect(wrapper.findAll('[aria-hidden="true"]')).toHaveLength(2)
    })

    /** ⚠️ A HINT AT A LAYOUT, NOT A REHEARSAL OF IT. */
    it('refuses to draw a page size of two hundred', () => {
        expect(mount(MhSkeleton, { props: { count: 200 } }).findAll('span')).toHaveLength(48)
    })

    it('survives a nonsensical count', () => {
        expect(mount(MhSkeleton, { props: { count: -5 } }).findAll('span')).toHaveLength(0)
    })
})

describe('the error state', () => {
    it('shows nothing at all when nothing failed', () => {
        expect(mount(MhErrorState, { props: { error: null } }).find('[role="alert"]').exists()).toBe(false)
    })

    /**
     * ⚠️ ANNOUNCED, BECAUSE IT REPLACES CONTENT SOMEBODY ASKED FOR. Silent, it leaves a screen
     * reader user waiting on a list that will never arrive.
     */
    it('announces the refusal', () => {
        const error = new MediaHubError(403, 'forbidden', 'You may not read this folder.')
        const wrapper = mount(MhErrorState, { props: { error } })

        expect(wrapper.attributes('role')).toBe('alert')
        expect(wrapper.text()).toContain('You may not read this folder.')
    })

    /**
     * ⚠️ THE KEY GOES TO THE SLOT, NOT INTO A SWITCH HERE. Only the host knows whether
     * `quota_exceeded` should offer to buy room or to go and delete something.
     */
    it('hands the stable key to whoever renders the sentence', () => {
        const error = new MediaHubError(422, 'quota_exceeded', 'No room left.')
        const wrapper = mount(MhErrorState, {
            props: { error },
            slots: { default: '<template #default="{ reason }">key:{{ reason }}</template>' },
        })

        expect(wrapper.text()).toContain('key:quota_exceeded')
    })

    /** ⚠️ NO BUTTON UNLESS RETRYING MEANS SOMETHING — a 403 does not become a 200 on a second click. */
    it('offers a retry only when asked', async () => {
        const error = new MediaHubError(500, null, 'Boom.')

        expect(mount(MhErrorState, { props: { error } }).find('button').exists()).toBe(false)

        const wrapper = mount(MhErrorState, { props: { error, retryLabel: 'Try again' } })
        await wrapper.find('button').trigger('click')

        expect(wrapper.emitted('retry')).toHaveLength(1)
    })
})
