<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { Media, MediaHubClient } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useMediaBrowser } from '../vue/useMediaBrowser'
import { useMediaPicker } from '../vue/useMediaPicker'
import type { MediaPickerRequest } from '../vue/useMediaPicker'
import MhEmptyState from './MhEmptyState.vue'
import MhItemGrid from './MhItemGrid.vue'
import { useNativeDialog } from './useNativeDialog'

/**
 * CHOOSING A FILE, AND GETTING IT BACK AS A PROMISE.
 *
 * ⚠️ `await picker.pick()` READS AT THE PLACE THE CHOICE IS NEEDED. An event-based picker forces
 * every caller to hold state between "I opened it" and "something came back", and every screen
 * reinvents that bookkeeping — usually forgetting the dismissal, which is the common case.
 *
 * ⚠️ AND A DISMISSAL RESOLVES WITH NOTHING; it does not reject. Closing a picker is the most
 * ordinary thing anyone does with one: rejecting would make every caller wrap the call in a
 * `try` to handle it, and whoever forgets gets an unhandled rejection for a click on "cancel".
 */
const props = withDefaults(
    defineProps<{
        /** Explicit client, for a host that would rather not provide one. */
        client?: MediaHubClient
        confirmLabel?: string
        cancelLabel?: string
        searchLabel?: string
        emptyTitle?: string
        columns?: number
        ui?: MhComponentOverride
    }>(),
    {
        client: undefined,
        confirmLabel: undefined,
        cancelLabel: undefined,
        searchLabel: undefined,
        emptyTitle: undefined,
        columns: 4,
        ui: undefined,
    },
)

const cls = useMediaTheme('mediaPicker', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    confirm: props.confirmLabel ?? t('picker.choose'),
    cancel: props.cancelLabel ?? t('picker.cancel'),
    search: props.searchLabel ?? t('picker.search'),
    empty: props.emptyTitle ?? t('picker.empty'),
}))

const picker = useMediaPicker()
const browser = useMediaBrowser(props.client)

const element = ref<HTMLDialogElement | null>(null)
const chosen = ref<string[]>([])
const term = ref('')

/*
 * A unique id for the search field.
 *
 * ⚠️ A HARDCODED ID COLLIDES THE MOMENT TWO PICKERS SHARE A PAGE, which is ordinary on a
 * form with a cover and a gallery. The label would then point at the first field, and clicking
 * the second one's label would focus the wrong input.
 */
const searchId = useId()

useNativeDialog(element, () => picker.open.value)

/*
 * ⚠️ THE LISTING IS FETCHED WHEN THE DIALOG OPENS, NOT WHEN THE COMPONENT MOUNTS. A picker
 * embedded beside a form is mounted on every page that carries one: loading a page of media each
 * time would mean a request per screen for a dialog most people never open.
 */
watch(
    () => picker.open.value,
    (open) => {
        if (!open) {
            return
        }

        chosen.value = []
        term.value = ''
        void browser.refresh()
    },
)

const shown = computed<Media[]>(() => {
    const page = browser.page.value?.media ?? []
    const types = picker.request.value?.types ?? []

    /*
     * ⚠️ FILTERED HERE *AND* ASKED FOR ON THE SERVER. The query carries the types, so paging and
     * counting stay right; this second pass only guards the case where a host supplied its own
     * client and answers whatever it likes. A picker restricted to images that hands back a
     * spreadsheet is worse than one that never filtered.
     */
    return types.length === 0 ? [...page] : page.filter((item) => types.includes(item.type))
})

const selectedMedia = computed<Media[]>(() =>
    chosen.value
        .map((id) => shown.value.find((item) => item.id === id))
        .filter((item): item is Media => item !== undefined),
)

const title = computed(() => picker.request.value?.title ?? t('picker.title'))

async function search(): Promise<void> {
    await browser.search(term.value)
}

function confirm(): void {
    picker.choose(selectedMedia.value)
}

function pick(options?: Partial<MediaPickerRequest>): Promise<Media[]> {
    const promise = picker.pick(options)

    void browser.filterByType(options?.types ?? [])

    return promise
}

/**
 * ⚠️ EXPOSED AS A METHOD RATHER THAN DRIVEN BY A PROP. `open` as a prop would put the caller back
 * in charge of the state the promise exists to remove, and the two would drift the first time
 * somebody closed the dialog without going through it.
 */
defineExpose({ pick, cancel: picker.cancel, open: picker.open })
</script>

<template>
    <dialog ref="element" :class="cls('root')" :aria-label="title" @cancel.prevent="picker.cancel()">
        <div :class="cls('header')">
            <p :class="cls('title')">{{ title }}</p>

            <form :class="cls('search')" role="search" @submit.prevent="search">
                <label :class="cls('searchLabel')" :for="searchId">{{ words.search }}</label>
                <input
                    :id="searchId"
                    v-model="term"
                    :class="cls('searchInput')"
                    type="search"
                    @search="search"
                />
            </form>
        </div>

        <div :class="cls('body')">
            <MhItemGrid
                v-model:selected="chosen"
                :media="shown"
                :multiple="picker.multiple.value"
                :loading="browser.loading.value"
                :error="browser.error.value"
                :columns="columns"
                @activate="picker.choose([$event])"
            >
                <template #empty>
                    <MhEmptyState :title="words.empty" />
                </template>
            </MhItemGrid>
        </div>

        <div :class="cls('actions')">
            <button type="button" :class="cls('cancel')" @click="picker.cancel()">
                {{ words.cancel }}
            </button>

            <!-- ⚠️ REFUSED WHILE NOTHING IS CHOSEN. A picker that answers with an empty list on a
                 deliberate confirmation is indistinguishable, to the caller, from a dismissal. -->
            <button
                type="button"
                :class="cls('confirm')"
                :disabled="selectedMedia.length === 0"
                @click="confirm"
            >
                {{ words.confirm }}
            </button>
        </div>
    </dialog>
</template>
