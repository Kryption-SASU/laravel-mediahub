<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Media } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * ONE MEDIA, SHOWN SMALL.
 *
 * ⚠️ A BROKEN IMAGE IS WORSE THAN NO IMAGE. A derivative that has not been built yet, a file
 * removed from the storage behind the library's back, a signature that expired while the tab sat
 * open: all three end as the browser's own broken-picture glyph, which says nothing and looks
 * like the application is broken. Every one of them falls back to the plain marker below.
 */
const props = withDefaults(
    defineProps<{
        media: Media
        /** Any CSS length. A number is read as pixels. */
        size?: number | string
        /**
         * ⚠️ `null` MEANS DECORATIVE, and it is not the same as leaving this out. Beside a
         * visible file name, a thumbnail repeating that name makes a screen reader say
         * everything twice; pass `null` there, and the name stays the only announcement.
         */
        alt?: string | null
        ui?: MhComponentOverride
    }>(),
    { size: '3rem', alt: undefined, ui: undefined },
)

const cls = useMediaTheme('thumbnail', () => props.ui)

const failed = ref(false)

/* ⚠️ A NEW MEDIA DESERVES A NEW ATTEMPT — otherwise one failure poisons the slot for every item
 * the browser later recycles through this same component instance, and a whole grid goes blank
 * after a single missing file. */
watch(
    () => props.media.id,
    () => {
        failed.value = false
    },
)

/**
 * ⚠️ THE ORIGINAL IS USED WHEN THERE IS NO DERIVATIVE, and that is a deliberate cost. This
 * package installs and works with no image library at all — in which case `thumbnail_url` is
 * ALWAYS null, and a component insisting on it would render a library where not one picture is
 * ever visible. The same holds for the minutes between an upload and the queue that follows it.
 * `loading` and `decoding` below are what make the cost bearable rather than an argument.
 */
const source = computed<string | null>(() => {
    if (failed.value) {
        return null
    }

    if (props.media.thumbnail_url) {
        return props.media.thumbnail_url
    }

    return props.media.type === 'image' ? props.media.url : null
})

const description = computed<string>(() => {
    if (props.alt !== undefined) {
        return props.alt ?? ''
    }

    const declared = props.media.custom_properties['alt']

    return typeof declared === 'string' && declared !== '' ? declared : props.media.name
})

/** The marker when there is nothing to show: the extension, or the kind when there is none. */
const marker = computed<string>(() => (props.media.extension ?? props.media.type).slice(0, 4))

const dimensions = computed(() => {
    const size = typeof props.size === 'number' ? `${props.size}px` : props.size

    return { width: size, height: size }
})
</script>

<template>
    <span :class="cls('root')" :style="dimensions">
        <img
            v-if="source"
            :class="cls('image')"
            :src="source"
            :alt="description"
            loading="lazy"
            decoding="async"
            @error="failed = true"
        />
        <span v-else :class="cls('fallback')" role="img" :aria-label="description || undefined">
            <slot name="fallback" :media="media">
                <span :class="cls('label')" aria-hidden="true">{{ marker }}</span>
            </slot>
        </span>
    </span>
</template>
