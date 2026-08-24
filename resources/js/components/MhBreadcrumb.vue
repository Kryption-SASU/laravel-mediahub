<script setup lang="ts">
import type { Folder } from '../client'
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
    { rootLabel: 'All files', label: 'Breadcrumb', ui: undefined },
)

defineEmits<{ open: [folder: Folder | null] }>()

const cls = useMediaTheme('breadcrumb', () => props.ui)
</script>

<template>
    <nav :class="cls('root')" :aria-label="label">
        <ol :class="cls('list')">
            <li :class="cls('item')">
                <button type="button" :class="cls('link')" @click="$emit('open', null)">
                    {{ rootLabel }}
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
