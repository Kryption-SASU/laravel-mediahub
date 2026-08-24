<script setup lang="ts">
import { computed } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * THE SHAPE OF WHAT IS COMING.
 *
 * ⚠️ THE PLACEHOLDERS ARE HIDDEN FROM ASSISTIVE TECHNOLOGY, and the wait is announced once
 * instead. Eight empty boxes read aloud one after another tell somebody nothing except that
 * their screen reader is stuck; a single "Loading" says what is happening.
 *
 * ⚠️ AND THE COUNT IS CAPPED. A skeleton is a hint at a layout, not a rehearsal of it: a page
 * size of two hundred would put two hundred pulsing nodes in the document to be thrown away
 * milliseconds later.
 */
const props = withDefaults(
    defineProps<{
        count?: number
        /** What the wait is called. Shown to nobody; read to those who need it. */
        label?: string
        ui?: MhComponentOverride
    }>(),
    { count: 8, label: undefined, ui: undefined },
)

const cls = useMediaTheme('skeleton', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    loading: props.label ?? t('skeleton.loading'),
}))

const items = computed(() => Math.max(0, Math.min(Math.trunc(props.count), 48)))
</script>

<template>
    <div :class="cls('root')" role="status" aria-busy="true" :aria-label="words.loading">
        <span v-for="index in items" :key="index" :class="cls('item')" aria-hidden="true" />
    </div>
</template>
