<script setup lang="ts">
import { computed } from 'vue'
import type { Media } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhThumbnail from './MhThumbnail.vue'

/**
 * ONE MEDIA, AS SOMETHING YOU CAN CHOOSE.
 *
 * ⚠️ IT IS AN OPTION, NOT A BUTTON. Inside a grid it carries `role="option"` and its selected
 * state, so assistive technology announces "3 of 24, selected" rather than reading twenty-four
 * unrelated buttons. That is not a detail on a picker: choosing a file is the only thing the
 * screen is for.
 *
 * ⚠️ AND IT IS NOT INDEPENDENTLY TABBABLE. Focus belongs to the grid — twenty-four items each
 * taking a tab stop turn a picker into something a keyboard user has to escape from rather than
 * use.
 *
 * ⚠️ IT DOES NOT RESTYLE THE THUMBNAIL EITHER. Passing its own classes down would silently
 * override whatever the host set on `thumbnail`, and the host would be left theming a component
 * that ignores them in the one place it is actually seen.
 */
const props = withDefaults(
    defineProps<{
        media: Media
        selected?: boolean
        /** Position in the grid, announced as "n of total". */
        index?: number
        total?: number
        /** Whether this is the item the grid's single tab stop currently sits on. */
        focused?: boolean
        ui?: MhComponentOverride
    }>(),
    { selected: false, index: undefined, total: undefined, focused: false, ui: undefined },
)

const cls = useMediaTheme('itemCard', () => props.ui)

const rootClasses = computed(() =>
    [cls('root'), props.selected ? cls('selected') : ''].join(' ').trim(),
)
</script>

<template>
    <div
        :class="rootClasses"
        role="option"
        :aria-selected="selected"
        :aria-setsize="total"
        :aria-posinset="index"
        :tabindex="focused ? 0 : -1"
    >
        <!-- ⚠️ THE FRAME IS WHAT GIVES THE THUMBNAIL A HEIGHT. Asked for `100%` inside a column
             that has none of its own, the picture falls back to its own dimensions, and a grid
             of portrait wallpapers beside videos comes out as a staircase. -->
        <span :class="cls('preview')">
            <!-- ⚠️ DECORATIVE, BECAUSE THE NAME IS RIGHT THERE. Left describing itself, a screen
                 reader announces the same file name twice for every item in the grid. -->
            <MhThumbnail :media="media" :alt="null" size="100%" />
        </span>

        <span :class="cls('name')" :title="media.name">{{ media.name }}</span>

        <slot name="meta" :media="media" />
    </div>
</template>
