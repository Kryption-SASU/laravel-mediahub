<script setup lang="ts">
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * NOTHING HERE — SAID ON PURPOSE.
 *
 * ⚠️ AN EMPTY FOLDER AND A SEARCH THAT MATCHED NOTHING ARE NOT THE SAME EVENT, and a single
 * wording for both is how a person concludes their files are gone. The component takes the
 * sentence rather than inventing one, so the screen that knows which case it is in can say so.
 */
const props = withDefaults(
    defineProps<{
        title: string
        description?: string
        ui?: MhComponentOverride
    }>(),
    { description: undefined, ui: undefined },
)

const cls = useMediaTheme('emptyState', () => props.ui)
</script>

<template>
    <div :class="cls('root')">
        <span v-if="$slots.icon" :class="cls('icon')" aria-hidden="true">
            <slot name="icon" />
        </span>

        <p :class="cls('title')">{{ title }}</p>

        <p v-if="description" :class="cls('description')">{{ description }}</p>

        <!-- ⚠️ NOT RENDERED WHEN EMPTY. An actions row with nothing in it still occupies space,
             and the gap reads as something that failed to load. -->
        <div v-if="$slots.actions" :class="cls('actions')">
            <slot name="actions" />
        </div>
    </div>
</template>
