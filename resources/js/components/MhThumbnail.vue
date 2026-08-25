<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Media } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { GLYPH_BOX, TYPE_GLYPHS } from './glyphs'

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

/**
 * WHAT IS DRAWN WHEN THERE IS NOTHING TO SHOW: the kind, as a picture.
 *
 * ⚠️ THE KIND IS NEVER PRINTED AS A WORD ANY MORE. It used to be the first four letters of the
 * extension, falling back to the kind — so a video with no extension rendered a tile with
 * "VIDE" across it, which is a French word, and the wrong one.
 */
const glyph = computed<readonly string[]>(() => TYPE_GLYPHS[props.media.type])

/**
 * ⚠️ THE EXTENSION STAYS, BECAUSE THE GLYPH DOES NOT SAY EVERYTHING. Six kinds cover every file
 * a server can send; "is this a PDF or a Word document" is the question actually being asked of
 * a document tile, and only these three or four letters answer it. Absent, nothing is printed —
 * an empty caption reserves a line for a word that never comes.
 */
const marker = computed<string>(() => props.media.extension ?? '')

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
            <!-- ⚠️ THE SLOT COMES FIRST IN THE CONTRACT, not the drawing. A host with an icon set
                 of its own replaces what is inside here; nothing about that requires forking the
                 component, which is the whole reason the markup can stay frozen. -->
            <slot name="fallback" :media="media" :glyph="glyph">
                <svg
                    :class="cls('icon')"
                    :viewBox="GLYPH_BOX"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path v-for="(drawing, step) in glyph" :key="step" :d="drawing" />
                </svg>

                <span v-if="marker" :class="cls('label')" aria-hidden="true">{{ marker }}</span>
            </slot>
        </span>
    </span>
</template>
