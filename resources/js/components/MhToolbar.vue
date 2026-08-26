<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import type { MediaSort, MediaType } from '../client'
import { MEDIA_TYPES } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * SEARCHING, SORTING, FILTERING.
 *
 * ⚠️ THE SORT OPTIONS COME FROM THE TYPE, NOT FROM A LIST TYPED HERE. `MediaSort` mirrors the
 * server's allow-list; a second list written into this template would drift the day a column is
 * added, and offer an order the server silently ignores — which reads as sorting being broken.
 *
 * ⚠️ THE SEARCH IS STILL A FORM, THOUGH IT NO LONGER WAITS FOR ONE. Enter submits, because that
 * is what a search box promises and because somebody who has finished typing should not have to
 * sit out a delay to be answered.
 *
 * ⚠️ IT ALSO SEARCHES AS YOU TYPE, WHICH IS THE PART THAT NEEDS CARE. A handler on every
 * keystroke turns a slow connection into a queue of answers arriving out of order, and asks for
 * "f", "fa", "fac", "fact" before the useful term exists. Two rules keep that from happening:
 * nothing is asked for until the typing has paused, and a term shorter than the floor counts as
 * no term at all.
 *
 * ⚠️ A TERM UNDER THE FLOOR CLEARS THE SEARCH RATHER THAN FREEZING IT. Deleting "invoice" down to
 * "i" would otherwise leave the results of a search whose term is no longer in the box, and the
 * only way back to the whole library would be to empty the field completely.
 *
 * ⚠️ BUT THE FLOOR IS A RULE ABOUT TYPING, NOT ABOUT WHAT MAY BE SEARCHED FOR. Enter is somebody
 * saying they have finished, so it asks for exactly what is in the box — a one-letter name is a
 * name, and refusing it would be the box arguing with what it was told.
 */
const props = withDefaults(
    defineProps<{
        search?: string | null
        /** Milliseconds of quiet before a typed term is asked for. */
        searchDelay?: number
        /** Shortest term the typing rule will ask for; anything shorter counts as no term. */
        searchFrom?: number
        sort?: MediaSort
        direction?: 'asc' | 'desc'
        types?: readonly MediaType[]
        /**
         * Whether the kind can be changed from here.
         *
         * ⚠️ FALSE WHERE THE CALLER HAS ALREADY DECIDED. A screen opened as "choose a video"
         * that offers a control to widen it back to everything has asked a question and then
         * ignored the answer — and the file somebody then picks is the one the caller said it
         * could not use.
         */
        filterable?: boolean
        searchLabel?: string
        sortLabel?: string
        typeLabel?: string
        allTypesLabel?: string
        ui?: MhComponentOverride
    }>(),
    {
        search: null,
        searchDelay: 300,
        searchFrom: 2,
        sort: 'created_at',
        direction: 'desc',
        types: () => [],
        filterable: true,
        searchLabel: undefined,
        sortLabel: undefined,
        typeLabel: undefined,
        allTypesLabel: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{
    search: [term: string]
    sort: [sort: MediaSort, direction: 'asc' | 'desc']
    filter: [types: MediaType[]]
}>()

const cls = useMediaTheme('toolbar', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    search: props.searchLabel ?? t('toolbar.search'),
    sort: props.sortLabel ?? t('toolbar.sort'),
    type: props.typeLabel ?? t('toolbar.type'),
    allTypes: props.allTypesLabel ?? t('toolbar.allTypes'),
}))

const searchId = useId()
const sortId = useId()
const typeId = useId()

const term = ref(props.search ?? '')

/* ⚠️ THE FIELD FOLLOWS THE QUERY. A host restoring a saved search, or clearing filters from
 * elsewhere, would otherwise leave this box showing a term nothing is filtered by. */
watch(
    () => props.search,
    (value) => {
        term.value = value ?? ''
    },
)

let pending: ReturnType<typeof setTimeout> | null = null

function cancel(): void {
    if (pending !== null) {
        clearTimeout(pending)
        pending = null
    }
}

/**
 * ⚠️ THE SAME TERM IS NEVER ASKED FOR TWICE, and this is not an optimisation. The field also
 * follows the query, so an answer arriving from outside writes into the box and wakes the
 * watcher below: without the comparison, every answer would schedule the request that produced
 * it, and the screen would spend its life re-asking for what it is already showing.
 */
function ask(wanted: string): void {
    cancel()

    if (wanted !== (props.search ?? '')) {
        emit('search', wanted)
    }
}

/** What the pause asks for: the term, or nothing at all if it is too short to be one. */
function fire(): void {
    const typed = term.value.trim()

    ask(typed.length >= props.searchFrom ? typed : '')
}

/** What Enter asks for: whatever is in the box. */
function submit(): void {
    ask(term.value.trim())
}

watch(term, () => {
    cancel()

    pending = setTimeout(fire, props.searchDelay)
})

/* ⚠️ A PENDING DELAY DIES WITH THE SCREEN. A timer left running fires into a component nobody is
 * looking at any more — and in a bench, into the next one. */
onBeforeUnmount(cancel)

/*
 * ⚠️ THE ORDERS COME FROM THE TYPE, THEIR NAMES FROM THE TRANSLATION. Two lists would drift:
 * one the server accepts, one the screen shows.
 */
const SORTS: readonly MediaSort[] = ['created_at', 'updated_at', 'name', 'size']

function onSort(event: Event): void {
    emit('sort', (event.target as HTMLSelectElement).value as MediaSort, props.direction)
}

function onDirection(): void {
    emit('sort', props.sort, props.direction === 'asc' ? 'desc' : 'asc')
}

function onType(event: Event): void {
    const value = (event.target as HTMLSelectElement).value

    emit('filter', value === '' ? [] : [value as MediaType])
}
</script>

<template>
    <div :class="cls('root')">
        <!-- ⚠️ WHAT YOU CAN DO COMES FIRST, AND IT COMES FROM THE SCREEN. Uploading and creating
             a folder need a client and a current folder; a toolbar that reached for either would
             stop being a toolbar. It offers the place, the screen above puts the controls in it.
             Absent, the row is not merely empty — the element is not rendered, so it takes none
             of the gap either. -->
        <div v-if="$slots.start" :class="cls('start')">
            <slot name="start" />
        </div>

        <div :class="cls('filters')">
            <div v-if="filterable" :class="cls('group')">
                <label :class="cls('label')" :for="typeId">{{ words.type }}</label>
                <select
                    :id="typeId"
                    :class="cls('select')"
                    :value="types[0] ?? ''"
                    @change="onType"
                >
                    <option value="">{{ words.allTypes }}</option>
                    <!-- ⚠️ THE KIND IS TRANSLATED, NOT PRINTED. `document` is a value in a
                         payload, not a word anybody chose to show somebody. -->
                    <option v-for="kind in MEDIA_TYPES" :key="kind" :value="kind">
                        {{ t('types.' + kind) }}
                    </option>
                </select>
            </div>

            <div :class="cls('group')">
                <label :class="cls('label')" :for="sortId">{{ words.sort }}</label>
                <select :id="sortId" :class="cls('select')" :value="sort" @change="onSort">
                    <option v-for="option in SORTS" :key="option" :value="option">
                        {{ t('toolbar.sort.' + option) }}
                    </option>
                </select>

                <!--
                    ⚠️ THE BUTTON SAYS WHAT IT WILL DO, NOT WHAT IS. An arrow alone is read out as
                    nothing at all, and "ascending" as a label leaves somebody unable to tell the
                    current state from the offered one.
                -->
                <button
                    type="button"
                    :class="cls('direction')"
                    :aria-label="direction === 'asc' ? t('toolbar.sortDescending') : t('toolbar.sortAscending')"
                    @click="onDirection"
                >
                    {{ direction === 'asc' ? '↑' : '↓' }}
                </button>
            </div>
        </div>

        <!-- ⚠️ LAST IN THE MARKUP, PUSHED TO THE END BY THE THEME. Placed there instead, it
             would be stranded mid-row the moment the toolbar wraps on a narrow screen. -->
        <form :class="cls('search')" role="search" @submit.prevent="submit">
            <label :class="cls('label')" :for="searchId">{{ words.search }}</label>
            <input :id="searchId" v-model="term" :class="cls('input')" type="search" />
        </form>

        <slot />
    </div>
</template>
