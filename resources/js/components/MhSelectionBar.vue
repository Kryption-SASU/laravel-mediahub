<script setup lang="ts">
import { computed } from 'vue'
import type { MediaHubClient, Selection } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import type { MhAction } from './actions'
import { useMediaActionList } from './actions'
import MhConfirmDialog from './MhConfirmDialog.vue'
import { useActionRunner } from './useActionRunner'

/**
 * WHAT IS SELECTED, AND WHAT CAN BE DONE WITH IT.
 *
 * ⚠️ IT RENDERS THE SAME LIST AS THE CONTEXT MENU. Not a list that looks like it — the same one,
 * from `useMediaActionList`, and a test compares the two. Two hand-kept lists diverge at the
 * first addition, and the screen then offers different things depending on where somebody
 * clicked, with nothing anywhere reporting a fault.
 */
const props = withDefaults(
    defineProps<{
        selection: Selection
        /** The host's own actions, replacing ours by identifier or appended. */
        actions?: MhAction[]
        clearLabel?: string
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    { actions: undefined, clearLabel: undefined, client: undefined, ui: undefined },
)

const emit = defineEmits<{ clear: []; done: [action: MhAction] }>()

const cls = useMediaTheme('selectionBar', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    clear: props.clearLabel ?? t('selection.clear'),
}))

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

const count = computed(
    () => (props.selection.media?.length ?? 0) + (props.selection.folders?.length ?? 0),
)

/*
 * ⚠️ THE COUNT IS ANNOUNCED, not merely drawn. Selecting with a keyboard gives no visual feedback
 * to somebody who cannot see the highlight; a live region is the only thing that says the tick
 * registered.
 */
</script>

<template>
    <div v-if="count > 0" :class="cls('root')" role="toolbar" :aria-label="t('selection.count', {}, count)">
        <p :class="cls('count')" role="status">{{ count }}</p>

        <div :class="cls('actions')">
            <button
                v-for="action in available"
                :key="action.id"
                type="button"
                :class="action.destructive ? cls('destructive') : cls('action')"
                :disabled="runner.running.value"
                @click="runner.request(action)"
            >
                {{ action.label }}
            </button>
        </div>

        <button type="button" :class="cls('clear')" @click="$emit('clear')">{{ words.clear }}</button>
    </div>

    <!-- ⚠️ OUTSIDE THE TOOLBAR, NOT INSIDE IT. A dialog is not a control of the bar, and
         nesting one inside 'role="toolbar"' tells assistive technology it is — while also
         disappearing along with the bar the moment the selection is emptied. -->
    <MhConfirmDialog
        :open="runner.pending.value !== null"
        :title="runner.pending.value?.confirm?.title ?? ''"
        :message="runner.pending.value?.confirm?.message"
        :destructive="runner.pending.value?.destructive ?? false"
        @confirm="runner.confirm()"
        @cancel="runner.cancel()"
    />
</template>
