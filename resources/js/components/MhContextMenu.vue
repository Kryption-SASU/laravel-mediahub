<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import type { MediaHubClient, Selection } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import type { MhAction } from './actions'
import { useMediaActionList } from './actions'
import type { MhActionSurfaces } from './actions'
import { GLYPH_BOX } from './glyphs'
import MhConfirmDialog from './MhConfirmDialog.vue'
import { useActionRunner } from './useActionRunner'

/**
 * THE SAME ACTIONS, WHERE THE POINTER IS.
 *
 * ⚠️ THE SAME LIST AS THE TOOLBAR, FROM THE SAME PLACE, and a test says so. This is the one rule
 * the design notes set for this component, and it is worth the sentence: a menu that offers less
 * than the bar is not reported by anything — the screen works, it simply does less in one place
 * than the other, and whoever notices assumes they misremembered.
 *
 * ⚠️ AND IT IS A MENU, WITH ARROW KEYS AND ESCAPE. A list of buttons in a floating box is a menu
 * to somebody looking at it and a pile of unrelated controls to somebody listening to it.
 */
const props = withDefaults(
    defineProps<{
        open: boolean
        selection: Selection
        /** Where the pointer was, in client coordinates. */
        x?: number
        y?: number
        actions?: MhAction[]
        /**
         * ⚠️ WHAT THIS MENU CAN OPEN, for the entries that are a surface rather than a request.
         * Absent, they are not offered — see `MhActionSurfaces`.
         */
        surfaces?: MhActionSurfaces
        label?: string
        /**
         * ⚠️ WHERE THE SCREEN IS, so that what is offered makes sense there. "Restore" on a file
         * nobody threw away fails, and a screen whose buttons fail teaches people to stop reading
         * them. The selection cannot answer this: it holds identifiers, and whether they are in
         * the trash is a fact about the view.
         */
        trashed?: boolean
        /**
         * ⚠️ WHETHER SOMEBODY IS BUILDING A BATCH. Half the entries act on exactly one thing —
         * a file cannot be renamed to two names — and are offered where one thing is being
         * pointed at rather than where a batch is being assembled. Reading "one is ticked"
         * instead would show "Rename" for as long as the batch held a single file, and take it
         * away as soon as a second was added.
         */
        picking?: boolean
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        x: 0,
        y: 0,
        actions: undefined,
        surfaces: undefined,
        label: undefined,
        trashed: false,
        picking: false,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{
    'update:open': [value: boolean]
    done: [action: MhAction]
    /**
     * ⚠️ WHAT IS BEING WORKED ON, SO THE SCREEN CAN DRAW IT WHERE IT BELONGS. This component
     * knows an act is running and knows nothing about where the files it names are on screen;
     * the screen knows the opposite. Neither can show the wait alone.
     */
    busy: [selection: Selection | null]
    progress: [seen: { done: number; total: number } | null]
}>()

const cls = useMediaTheme('contextMenu', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    menu: props.label ?? t('menu.label'),
}))

const api = resolveMediaHub(props.client)

const { available } = useMediaActionList(
    api,
    () => props.selection,
    () => props.actions,
    () => ({ trashed: props.trashed, picking: props.picking }),
    () => props.surfaces,
)

const runner = useActionRunner(
    () => props.selection,
    (action) => emit('done', action),
)


/* ⚠️ WATCHED RATHER THAN EMITTED FROM THE HANDLER. The runner clears it in a `finally`, on a
 * path that also runs when the act failed — reporting from the call site would leave a tile
 * spinning for ever after an error. */
watch(
    () => runner.busy.value,
    (selection) => emit('busy', selection),
)

/* ⚠️ THE FIGURE TRAVELS THE SAME WAY THE MARK DOES, and for the same reason: the
 * runner clears it in a finally, including after a failure. Emitted from the handler it
 * would stay on the screen once the act had gone. */
watch(
    () => runner.progress.value,
    (seen) => emit('progress', seen),
)

const root = ref<HTMLElement | null>(null)
const cursor = ref(0)

/*
 * ⚠️ FOCUS ENTERS THE MENU WHEN IT OPENS, and that is what makes it operable at all: a menu that
 * appears without taking focus leaves a keyboard user pressing Tab through the whole page to
 * reach something that opened under their hand.
 */
/**
 * DISMISSING IT BY CLICKING SOMEWHERE ELSE — which is the only way most people ever close a menu.
 *
 * ⚠️ WITHOUT THIS THERE IS NO POINTER ROUTE OUT AT ALL. Escape closes it and choosing an action
 * closes it, but somebody who opened the menu by mistake and clicked away was left with it
 * floating over the screen for as long as the page lived. That reads as a menu that never closes,
 * which is exactly how it was reported.
 *
 * ⚠️ `pointerdown` RATHER THAN `click`, and it matters twice. A menu that waits for `click` stays
 * up through a drag that started outside it; and a right click on another tile fires `pointerdown`
 * before `contextmenu`, so the menu closes and reopens where the pointer now is instead of
 * ignoring the second request.
 */
function onPointerDown(event: Event): void {
    const target = event.target

    /* ⚠️ INSIDE THE MENU IS NOT OUTSIDE IT. Closing on the way down would take the box away
     * before the `click` that chooses an action ever reached it. */
    if (target instanceof Node && root.value?.contains(target) === true) {
        return
    }

    close()
}

function listen(on: boolean): void {
    if (typeof document === 'undefined') {
        return
    }

    const method = on ? 'addEventListener' : 'removeEventListener'

    document[method]('pointerdown', onPointerDown)
}

/*
 * ⚠️ THE LISTENER IS RELEASED WITH THE COMPONENT. A screen opened and closed all day would
 * otherwise leave one behind on the document for every menu ever mounted, each holding its own
 * component alive through the closure.
 */
onBeforeUnmount(() => listen(false))

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            listen(false)

            return
        }

        cursor.value = 0
        await nextTick()
        focusItem()

        /*
         * ⚠️ ATTACHED AFTER THE RENDER, NOT DURING IT. The very gesture that opens the menu is
         * still being dispatched: a listener added synchronously would catch the tail of it and
         * close the menu in the same breath as opening it.
         */
        listen(true)
    },
    /*
     * ⚠️ `immediate`, BECAUSE A MENU CAN BE MOUNTED ALREADY OPEN. Without it such a menu takes no
     * focus and attaches nothing: it cannot be driven from a keyboard and cannot be dismissed by
     * clicking away — the two things this watcher exists to provide. Our own screen mounts it
     * closed and toggles, so nothing here ever showed it; a host rendering it open on demand
     * would have found a menu that only half works.
     */
    { immediate: true },
)

function focusItem(): void {
    const items = root.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')

    items?.[cursor.value]?.focus()
}

async function move(by: number): Promise<void> {
    const count = available.value.length

    if (count === 0) {
        return
    }

    /* ⚠️ A MENU WRAPS, unlike a grid: there is no spatial expectation to violate, and reaching the
     * last item from the first is what every other menu on the machine does. */
    cursor.value = (cursor.value + by + count) % count

    await nextTick()
    focusItem()
}

function close(): void {
    emit('update:open', false)
}

async function choose(action: MhAction): Promise<void> {
    close()
    await runner.request(action)
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault()
        close()

        return
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault()
        void move(event.key === 'ArrowDown' ? 1 : -1)
    }
}
</script>

<template>
    <div
        v-if="open"
        ref="root"
        :class="cls('root')"
        role="menu"
        :aria-label="words.menu"
        :style="{ left: `${x}px`, top: `${y}px` }"
        @keydown="onKeydown"
    >
        <button
            v-for="action in available"
            :key="action.id"
            type="button"
            role="menuitem"
            :class="action.destructive ? cls('destructive') : cls('item')"
            :disabled="runner.running.value"
            @click="choose(action)"
        >
            <!-- ⚠️ THE DRAWING BELONGS TO THE ACTION, and an entry without one still renders:
                 a host adding "Publish" gets a label rather than a hole where an icon would
                 be, and the column does not lose its alignment over it. -->
            <slot name="icon" :action="action">
                <svg
                    v-if="action.icon"
                    :class="cls('icon')"
                    :viewBox="GLYPH_BOX"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path v-for="(drawing, step) in action.icon" :key="step" :d="drawing" />
                </svg>
            </slot>

            {{ action.label }}
        </button>
    </div>

    <MhConfirmDialog
        :open="runner.pending.value !== null"
        :title="runner.asking.value?.title ?? ''"
        :message="runner.asking.value?.message"
        :destructive="runner.pending.value?.destructive ?? false"
        @confirm="runner.confirm()"
        @cancel="runner.cancel()"
    />
</template>
