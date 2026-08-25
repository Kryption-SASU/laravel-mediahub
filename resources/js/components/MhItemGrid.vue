<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { Media, MediaHubError } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhErrorState from './MhErrorState.vue'
import MhItemCard from './MhItemCard.vue'
import MhSkeleton from './MhSkeleton.vue'

/**
 * A GRID OF MEDIA YOU CAN CHOOSE FROM.
 *
 * ⚠️ ONE TAB STOP FOR THE WHOLE GRID, AND THE ARROWS MOVE INSIDE IT. This is the roving tabindex
 * pattern, and it is not a refinement: twenty-four items each taking a tab stop means a keyboard
 * user presses Tab twenty-four times to get past a picker, and every screen that embeds one
 * becomes slower to leave than to use.
 *
 * ⚠️ SELECTION IS THE CALLER'S STATE, NOT OURS. The grid says what was asked for and renders what
 * it is given; holding a second copy here is how a screen ends up showing three items ticked
 * while the form posts two.
 */
const props = withDefaults(
    defineProps<{
        media: readonly Media[]
        /** Identifiers, in the order they were chosen. */
        selected?: readonly string[]
        multiple?: boolean
        loading?: boolean
        error?: MediaHubError | null
        /**
         * ⚠️ HOW MANY PER ROW, FOR THE KEYBOARD — not for the layout, which CSS decides. Left
         * unsaid, Up and Down move by one item: honest, and better than guessing a number and
         * sending focus somewhere the eye did not follow.
         */
        columns?: number
        /**
         * ⚠️ WHAT A CLICK MEANS, AND IT DEFAULTS TO CHOOSING because that is what a grid of
         * options is for — a picker dialog and a form field both depend on it. A screen that also
         * lets somebody merely look sets this to false, and a click then says "show me this"
         * instead. Doing both at once is how a batch action quietly gathers every file anybody
         * glanced at, and its confirmation names a count rather than the files.
         */
        choosing?: boolean
        /**
         * The keys something is being done to right now.
         *
         * ⚠️ A LIST RATHER THAN A FLAG, because a batch is the ordinary case. Trashing nine files
         * marks the nine, so the wait is drawn where the work is rather than over the screen.
         */
        busy?: readonly string[]
        ui?: MhComponentOverride
    }>(),
    {
        selected: () => [],
        multiple: false,
        loading: false,
        error: null,
        columns: 1,
        choosing: true,
        busy: () => [],
        ui: undefined,
    },
)

const emit = defineEmits<{
    'update:selected': [ids: string[]]
    activate: [media: Media]
    /**
     * ⚠️ THE ONE JUST ACTED ON — which is not the same as the selection. A screen showing what
     * you picked has to be told by a single click; asking for a double one means the details
     * panel sits empty for anybody who does not think to try, and the first click looks as
     * though it did nothing.
     */
    current: [media: Media]
    /** Where the actions for one item were asked for, and at which point on screen. */
    menu: [media: Media, event: MouseEvent]
}>()

const cls = useMediaTheme('itemGrid', () => props.ui)
const t = useMediaText()

const root = ref<HTMLElement | null>(null)
const cursor = ref(0)

/* ⚠️ A CURSOR PAST THE END LEAVES THE GRID WITH NO TAB STOP AT ALL. Paging to a shorter last
 * page, or a search that narrows the results, does exactly that — and the grid then cannot be
 * reached by keyboard at all, silently. */
watch(
    () => props.media.length,
    (length) => {
        if (cursor.value >= length) {
            cursor.value = Math.max(0, length - 1)
        }
    },
)

const isSelected = (media: Media): boolean => props.selected.includes(media.id)

function toggle(media: Media): void {
    /*
     * ⚠️ ONE MEANING AT A TIME. Browsing, the click says "show me this" and ticks nothing —
     * otherwise every file somebody merely looked at joins the next batch action, whose
     * confirmation names a count rather than the files. Choosing, it ticks and shows nothing.
     */
    if (!props.choosing) {
        emit('current', media)

        return
    }

    if (!props.multiple) {
        emit('update:selected', isSelected(media) ? [] : [media.id])

        return
    }

    const next = isSelected(media)
        ? props.selected.filter((id) => id !== media.id)
        : [...props.selected, media.id]

    emit('update:selected', [...next])
}

async function moveTo(index: number): Promise<void> {
    if (props.media.length === 0) {
        return
    }

    cursor.value = Math.max(0, Math.min(index, props.media.length - 1))

    await nextTick()

    /*
     * ⚠️ FOCUS FOLLOWS THE CURSOR, and it has to be moved by hand. Changing which item is
     * tabbable does not move the caret: without this, the arrow keys would repaint an outline
     * while the browser's focus stayed on the item somebody left, and the next Enter would
     * choose the wrong file.
     */
    const options = root.value?.querySelectorAll<HTMLElement>('[role="option"]')

    options?.[cursor.value]?.focus()
}

function onKeydown(event: KeyboardEvent): void {
    const step = Math.max(1, Math.trunc(props.columns))
    const current = cursor.value

    const moves: Record<string, number | undefined> = {
        ArrowRight: current + 1,
        ArrowLeft: current - 1,
        ArrowDown: current + step,
        ArrowUp: current - step,
        Home: 0,
        End: props.media.length - 1,
    }

    const target = moves[event.key]

    if (target !== undefined) {
        event.preventDefault()
        void moveTo(target)

        return
    }

    const media = props.media[current]

    if (!media) {
        return
    }

    /* ⚠️ SPACE CHOOSES, ENTER OPENS — and Space must not also scroll the page underneath. */
    if (event.key === ' ') {
        event.preventDefault()
        toggle(media)
    }

    if (event.key === 'Enter') {
        event.preventDefault()
        emit('activate', media)
    }
}

/* ⚠️ COUNTED, SO PLURALISED — and the rule for that belongs to the language, not to us. */
const label = computed(() => t('grid.count', {}, props.media.length))
</script>

<template>
    <!-- ⚠️ NO `ui` PASSED DOWN. Restyling a child from here would override whatever the host set
         on that child globally, and leave them theming a component that ignores them in the one
         place it is actually seen. -->
    <MhSkeleton v-if="loading" :count="8" />

    <MhErrorState v-else-if="error" :error="error" />

    <div v-else-if="media.length === 0" :class="cls('empty')">
        <slot name="empty" />
    </div>

    <div
        v-else
        ref="root"
        :class="cls('root')"
        role="listbox"
        :aria-multiselectable="multiple"
        :aria-label="label"
        @keydown="onKeydown"
    >
        <MhItemCard
            v-for="(item, position) in media"
            :key="item.id"
            :media="item"
            :selected="isSelected(item)"
            :index="position + 1"
            :total="media.length"
            :focused="position === cursor"
            :picking="choosing"
            :busy="busy.includes(item.id)"
            @click="toggle(item)"
            @dblclick="$emit('activate', item)"
            @menu="emit('menu', item, $event)"
        >
            <template #meta="slotProps">
                <slot name="meta" v-bind="slotProps" />
            </template>
        </MhItemCard>
    </div>
</template>
