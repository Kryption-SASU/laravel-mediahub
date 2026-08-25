<script setup lang="ts">
import { computed, watch } from 'vue'
import type { MediaHubClient, Selection } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import type { MhAction } from './actions'
import { useMediaActionList } from './actions'
import { GLYPH_BOX } from './glyphs'
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
        actions: undefined,
        clearLabel: undefined,
        trashed: false,
        picking: true,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{
    clear: []
    done: [action: MhAction]
    /** ⚠️ See `MhContextMenu`: the act is known here, its place on screen is known there. */
    busy: [selection: Selection | null]
}>()

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
    /* ⚠️ THIS BAR IS THE BATCH, so it says so by default. It only ever appears while somebody
     * is assembling one; a caller has to go out of its way to claim otherwise. */
    () => ({ trashed: props.trashed, picking: props.picking }),
)

/* ⚠️ WATCHED RATHER THAN EMITTED FROM THE HANDLER. The runner clears it in a `finally`, on a
 * path that also runs when the act failed — reporting from the call site would leave every
 * ticked tile spinning for ever after an error. */
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

watch(
    () => runner.busy.value,
    (selection) => emit('busy', selection),
)
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
                <!-- ⚠️ THE SAME DRAWING AS THE MENU, from the same action. Two renderers holding
                     their own icon table would show a different picture for one act depending on
                     where somebody clicked. -->
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

        <button type="button" :class="cls('clear')" @click="$emit('clear')">{{ words.clear }}</button>
    </div>

    <!-- ⚠️ OUTSIDE THE TOOLBAR, NOT INSIDE IT. A dialog is not a control of the bar, and
         nesting one inside 'role="toolbar"' tells assistive technology it is — while also
         disappearing along with the bar the moment the selection is emptied. -->
    <MhConfirmDialog
        :open="runner.pending.value !== null"
        :title="runner.asking.value?.title ?? ''"
        :message="runner.asking.value?.message"
        :destructive="runner.pending.value?.destructive ?? false"
        @confirm="runner.confirm()"
        @cancel="runner.cancel()"
    />
</template>
