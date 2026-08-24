<script setup lang="ts">
import { computed } from 'vue'
import type { Folder } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * WHERE YOU ARE, AND HOW TO GET BACK.
 *
 * ⚠️ IT IS A NAVIGATION LANDMARK, and the current folder is not a link. Rendering the last
 * segment as a link too means a screen reader announces a route to the page somebody is already
 * on, and a click that appears to do nothing.
 */
const props = withDefaults(
    defineProps<{
        trail: readonly Folder[]
        rootLabel?: string
        label?: string
        ui?: MhComponentOverride
    }>(),
    { rootLabel: undefined, label: undefined, ui: undefined },
)

defineEmits<{ open: [folder: Folder | null] }>()

const cls = useMediaTheme('breadcrumb', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    root: props.rootLabel ?? t('breadcrumb.root'),
    label: props.label ?? t('breadcrumb.label'),
}))
</script>

<template>
    <nav :class="cls('root')" :aria-label="words.label">
        <ol :class="cls('list')">
            <li :class="cls('item')">
                <button type="button" :class="cls('link')" @click="$emit('open', null)">
                    {{ words.root }}
                </button>
            </li>

            <li v-for="(folder, index) in trail" :key="folder.id" :class="cls('item')">
                <span :class="cls('separator')" aria-hidden="true">/</span>

                <!-- ⚠️ THE LAST ONE IS TEXT, NOT A CONTROL, and says so with `aria-current`. -->
                <span
                    v-if="index === trail.length - 1"
                    :class="cls('current')"
                    aria-current="page"
                >
                    {{ folder.name }}
                </span>

                <button v-else type="button" :class="cls('link')" @click="$emit('open', folder)">
                    {{ folder.name }}
                </button>
            </li>
        </ol>
    </nav>
</template>
