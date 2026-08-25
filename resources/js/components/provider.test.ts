import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { describe, expect, it } from 'vitest'
import { fakeClient } from '../vue/fake.test-utils'
import { resolveMediaHub } from '../vue/context'
import { useMediaTheme } from '../theme/context'
import { createMediaHub } from './install'
import MhProvider from './MhProvider.vue'
import MhSkeleton from './MhSkeleton.vue'

/** A child that reports what it managed to obtain from above. */
const Probe = defineComponent({
    setup() {
        const client = resolveMediaHub()
        const cls = useMediaTheme('thumbnail')

        return () => h('div', { 'data-classes': cls('root'), 'data-client': typeof client })
    },
})

describe('providing the client and the theme', () => {
    /**
     * ⚠️ THE PROVIDER RENDERS NOTHING OF ITS OWN. A wrapper `<div>` changes the layout of
     * whatever it is dropped into, and the host meets it as a broken grid rather than as a
     * decision somebody took.
     */
    it('adds no element of its own', () => {
        const wrapper = mount(MhProvider, {
            props: { client: fakeClient() },
            slots: { default: '<p>content</p>' },
        })

        expect(wrapper.html()).toBe('<p>content</p>')
    })

    it('hands the client down', () => {
        const wrapper = mount(MhProvider, {
            props: { client: fakeClient() },
            slots: { default: () => h(Probe) },
        })

        expect(wrapper.find('div').attributes('data-client')).toBe('object')
    })

    it('lets a host theme through to a component below', () => {
        const wrapper = mount(MhProvider, {
            props: { client: fakeClient(), theme: { thumbnail: { root: { class: 'from-host' } } } },
            slots: { default: () => h(Probe) },
        })

        expect(wrapper.find('div').attributes('data-classes')).toContain('from-host')
    })

    /**
     * ⚠️ AND IT FOLLOWS A CHANGE. A host offering a dark mode, or a palette chosen at runtime,
     * changes the override; a snapshot resolved once would leave every mounted component showing
     * the previous skin until something unrelated happened to re-render it.
     */
    it('follows the theme when it changes', async () => {
        const wrapper = mount(MhProvider, {
            props: { client: fakeClient(), theme: { thumbnail: { root: { class: 'day' } } } },
            slots: { default: () => h(Probe) },
        })

        await wrapper.setProps({ theme: { thumbnail: { root: { class: 'night' } } } })

        expect(wrapper.find('div').attributes('data-classes')).toContain('night')
    })
})

describe('adjusting one component on the spot', () => {
    /**
     * ⚠️ THE NEAREST WORD WINS: default, then the host's theme, then this prop. A component that
     * cannot be adjusted where it stands gets copied instead, and a copy is the one outcome the
     * whole design exists to avoid.
     */
    it('lets the ui prop beat the host theme', () => {
        const wrapper = mount(MhProvider, {
            props: { client: fakeClient(), theme: { skeleton: { item: { class: 'from-host' } } } },
            slots: { default: () => h(MhSkeleton, { count: 1, ui: { item: { class: 'right-here' } } }) },
        })

        const classes = wrapper.find('span').classes()

        expect(classes).toContain('right-here')
        expect(classes).not.toContain('from-host')
    })

    /** ⚠️ AND IT STILL CANNOT TAKE THE STRUCTURE — the markup is the contract wherever it is said. */
    it('cannot drop the structure either', () => {
        const wrapper = mount(MhSkeleton, { props: { count: 1, ui: { item: { class: '' } } } })

        expect(wrapper.find('span').classes()).toContain('animate-pulse')
    })
})

describe('the public surface of the components', () => {
    /**
     * ⚠️ EXHAUSTIVE, AND `toEqual` RATHER THAN `toContain`. Everything named here is what a host
     * imports: taking one back is a breaking change, and adding one without deciding to is how a
     * surface grows things nobody can remove afterwards.
     */
    it('is what the barrel says it is', async () => {
        const barrel = await import('./index')

        expect(Object.keys(barrel).sort()).toEqual([
            'MH_DEFAULT_LOCALE',
            'MH_LOCALES',
            'MhBreadcrumb',
            'MhConfirmDialog',
            'MhContextMenu',
            'MhDetailsDialog',
            'MhDetailsPanel',
            'MhDropzone',
            'MhEmptyState',
            'MhErrorState',
            'MhFolderCreator',
            'MhFolderList',
            'MhItemCard',
            'MhItemGrid',
            'MhMediaGallery',
            'MhMediaInput',
            'MhMediaLibrary',
            'MhMediaPicker',
            'MhPager',
            'MhProvider',
            'MhQuotaMeter',
            'MhSelectionBar',
            'MhSkeleton',
            'MhThumbnail',
            'MhToolbar',
            'MhUploadButton',
            'MhUploadQueue',
            'classesOf',
            'createMediaHub',
            'createTranslator',
            'defaultActions',
            'defaultTheme',
            'mediaTextKey',
            'mediaThemeKey',
            'mergeTheme',
            'provideMediaText',
            'provideMediaTheme',
            'useActionRunner',
            'useMediaActionList',
            'useMediaText',
            'useMediaTheme',
        ])
    })
})

describe('installing it on the application', () => {
    it('serves the same two things as the provider', () => {
        const wrapper = mount(Probe, {
            global: { plugins: [createMediaHub({ client: fakeClient(), theme: { thumbnail: { root: { class: 'installed' } } } })] },
        })

        expect(wrapper.attributes('data-client')).toBe('object')
        expect(wrapper.attributes('data-classes')).toContain('installed')
    })

    /**
     * ⚠️ `app.provide()` TAKES A VALUE ONCE AND FOR ALL. Without a way to change it afterwards, a
     * host wanting a dark mode would have to recreate the whole application to change a colour —
     * so the plugin keeps the handle rather than pretending the question does not arise.
     */
    it('can change the skin after installation', async () => {
        const mediahub = createMediaHub({ client: fakeClient() })
        const wrapper = mount(Probe, { global: { plugins: [mediahub] } })

        mediahub.setTheme({ thumbnail: { root: { class: 'after-the-fact' } } })
        await wrapper.vm.$nextTick()

        expect(wrapper.attributes('data-classes')).toContain('after-the-fact')
    })

    it('falls back to the default skin when nothing was overridden', () => {
        const wrapper = mount(Probe, {
            global: { plugins: [createMediaHub({ client: fakeClient() })] },
        })

        expect(wrapper.attributes('data-classes')).toContain('overflow-hidden')
    })
})
