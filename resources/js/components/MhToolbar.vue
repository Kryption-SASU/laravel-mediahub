<script setup lang="ts">
import { ref, useId, watch } from 'vue'
import type { MediaSort, MediaType } from '../client'
import { MEDIA_TYPES } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * SEARCHING, SORTING, FILTERING.
 *
 * ⚠️ THE SORT OPTIONS COME FROM THE TYPE, NOT FROM A LIST TYPED HERE. `MediaSort` mirrors the
 * server's allow-list; a second list written into this template would drift the day a column is
 * added, and offer an order the server silently ignores — which reads as sorting being broken.
 *
 * ⚠️ AND THE SEARCH IS A FORM. Submitting on Enter is what everybody expects from a search box,
 * and a keyup handler that fires a request per keystroke turns a slow connection into a queue of
 * answers arriving out of order.
 */
const props = withDefaults(
    defineProps<{
        search?: string | null
        sort?: MediaSort
        direction?: 'asc' | 'desc'
        types?: readonly MediaType[]
        searchLabel?: string
        sortLabel?: string
        typeLabel?: string
        allTypesLabel?: string
        ui?: MhComponentOverride
    }>(),
    {
        search: null,
        sort: 'created_at',
        direction: 'desc',
        types: () => [],
        searchLabel: 'Search',
        sortLabel: 'Sort by',
        typeLabel: 'Kind',
        allTypesLabel: 'Everything',
        ui: undefined,
    },
)

const emit = defineEmits<{
    search: [term: string]
    sort: [sort: MediaSort, direction: 'asc' | 'desc']
    filter: [types: MediaType[]]
}>()

const cls = useMediaTheme('toolbar', () => props.ui)

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

const SORTS: ReadonlyArray<{ value: MediaSort; label: string }> = [
    { value: 'created_at', label: 'Date added' },
    { value: 'updated_at', label: 'Last changed' },
    { value: 'name', label: 'Name' },
    { value: 'size', label: 'Size' },
]

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
        <form :class="cls('search')" role="search" @submit.prevent="$emit('search', term)">
            <label :class="cls('label')" :for="searchId">{{ searchLabel }}</label>
            <input :id="searchId" v-model="term" :class="cls('input')" type="search" />
        </form>

        <div :class="cls('group')">
            <label :class="cls('label')" :for="typeId">{{ typeLabel }}</label>
            <select
                :id="typeId"
                :class="cls('select')"
                :value="types[0] ?? ''"
                @change="onType"
            >
                <option value="">{{ allTypesLabel }}</option>
                <option v-for="kind in MEDIA_TYPES" :key="kind" :value="kind">{{ kind }}</option>
            </select>
        </div>

        <div :class="cls('group')">
            <label :class="cls('label')" :for="sortId">{{ sortLabel }}</label>
            <select :id="sortId" :class="cls('select')" :value="sort" @change="onSort">
                <option v-for="option in SORTS" :key="option.value" :value="option.value">
                    {{ option.label }}
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
                :aria-label="direction === 'asc' ? 'Sort descending' : 'Sort ascending'"
                @click="onDirection"
            >
                {{ direction === 'asc' ? '↑' : '↓' }}
            </button>
        </div>

        <slot />
    </div>
</template>
