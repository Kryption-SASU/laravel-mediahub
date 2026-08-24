<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import type { MediaHubClient, Selection } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import type { MhAction } from './actions'
import { useMediaActionList } from './actions'
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
        label?: string
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        x: 0,
        y: 0,
        actions: undefined,
        label: 'Actions',
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; done: [action: MhAction] }>()

const cls = useMediaTheme('contextMenu', () => props.ui)

const api = resolveMediaHub(props.client)

const { available } = useMediaActionList(
    api,
    () => props.selection,
    () => props.actions,
)

const runner = useActionRunner(
    () => props.selection,
    (action) => emit('done', action),
)

const root = ref<HTMLElement | null>(null)
const cursor = ref(0)

/*
 * ⚠️ FOCUS ENTERS THE MENU WHEN IT OPENS, and that is what makes it operable at all: a menu that
 * appears without taking focus leaves a keyboard user pressing Tab through the whole page to
 * reach something that opened under their hand.
 */
watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return
        }

        cursor.value = 0
        await nextTick()
        focusItem()
    },
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
        :aria-label="label"
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
            {{ action.label }}
        </button>
    </div>

    <MhConfirmDialog
        :open="runner.pending.value !== null"
        :title="runner.pending.value?.confirm?.title ?? ''"
        :message="runner.pending.value?.confirm?.message"
        :destructive="runner.pending.value?.destructive ?? false"
        @confirm="runner.confirm()"
        @cancel="runner.cancel()"
    />
</template>
