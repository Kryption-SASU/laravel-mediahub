<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Folder, Media, MediaHubClient, MediaSort, MediaType } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useMediaBrowser } from '../vue/useMediaBrowser'
import { useQuota } from '../vue/useQuota'
import { useSelection } from '../vue/useSelection'
import { useUpload } from '../vue/useUpload'
import type { MhAction } from './actions'
import MhBreadcrumb from './MhBreadcrumb.vue'
import MhContextMenu from './MhContextMenu.vue'
import MhDetailsPanel from './MhDetailsPanel.vue'
import MhDropzone from './MhDropzone.vue'
import MhEmptyState from './MhEmptyState.vue'
import MhFolderList from './MhFolderList.vue'
import MhItemGrid from './MhItemGrid.vue'
import MhQuotaMeter from './MhQuotaMeter.vue'
import MhSelectionBar from './MhSelectionBar.vue'
import MhToolbar from './MhToolbar.vue'
import MhUploadQueue from './MhUploadQueue.vue'

/**
 * THE WHOLE SCREEN — AND IT INVENTS NOTHING.
 *
 * ⚠️ THIS FILE IS WIRING, AND THAT IS THE POINT. Everything it does goes through the composables
 * of layer 2 and the components of layer 3; there is no state here that those do not already
 * hold, and no request this file makes itself. Had the assembly needed to reach past a layer,
 * that layer would have been cut wrong — and this is the moment such a thing shows.
 *
 * ⚠️ IT IS ALSO THE COMPONENT MOST LIKELY TO BE REPLACED. A host who wants a different screen
 * writes their own version of this file, on the same composables, and keeps every component
 * below it. That is why the wiring is thin: it is meant to be readable as an example.
 */
const props = withDefaults(
    defineProps<{
        client?: MediaHubClient
        /** The host's own actions, added to the toolbar and the menu alike. */
        actions?: MhAction[]
        emptyTitle?: string
        emptyDescription?: string
        ui?: MhComponentOverride
    }>(),
    {
        client: undefined,
        actions: undefined,
        emptyTitle: 'Nothing here yet',
        emptyDescription: 'Drop files, or choose them from your computer.',
        ui: undefined,
    },
)

const emit = defineEmits<{ open: [media: Media] }>()

const cls = useMediaTheme('mediaLibrary', () => props.ui)

const browser = useMediaBrowser(props.client)
const selection = useSelection()
const quota = useQuota(props.client)
const upload = useUpload(props.client)

const focused = ref<Media | null>(null)
const menu = ref({ open: false, x: 0, y: 0 })

onMounted(() => {
    void browser.refresh()
    void quota.refresh()
})

const chosen = computed<string[]>({
    get: () => selection.media.value,
    set: (ids) => {
        selection.media.value = [...ids]
    },
})

/*
 * ⚠️ A SEARCH THAT RETURNS NOTHING IS NOT AN EMPTY FOLDER. Saying "nothing here yet" to somebody
 * who just searched suggests their files are gone; the sentence has to follow what was asked.
 */
const searching = computed(() => (browser.query.value.search ?? '') !== '')

const emptyTitle = computed(() => (searching.value ? 'No results' : props.emptyTitle))

const emptyDescription = computed(() =>
    searching.value ? 'Nothing matches what you searched for.' : props.emptyDescription,
)

async function open(folder: Folder | null): Promise<void> {
    /* ⚠️ THE SELECTION IS DROPPED WHEN THE FOLDER CHANGES. Carrying it across means a batch
     * action runs on files nobody can see any more — and the confirmation names a count rather
     * than the files, so nothing on screen would give it away. */
    selection.clear()
    focused.value = null

    await browser.open(folder)
}

async function refreshAll(): Promise<void> {
    await browser.refresh()
    await quota.refresh()
}

function onContextMenu(event: MouseEvent): void {
    /* ⚠️ ONLY WHERE THERE IS SOMETHING TO ACT ON. A menu offering nothing, opened by a right
     * click on the background, replaces the browser's own menu with an empty box. */
    if (selection.empty.value) {
        return
    }

    event.preventDefault()
    menu.value = { open: true, x: event.clientX, y: event.clientY }
}

async function onFiles(files: File[]): Promise<void> {
    upload.add(files, { folder: browser.folder.value?.id ?? null })
}

function onSorted(sort: MediaSort, direction: 'asc' | 'desc'): void {
    void browser.sortBy(sort, direction)
}

function onFiltered(types: MediaType[]): void {
    void browser.filterByType(types)
}
</script>

<template>
    <section :class="cls('root')" @contextmenu="onContextMenu">
        <header :class="cls('header')">
            <MhBreadcrumb :trail="browser.breadcrumbs.value" @open="open" />

            <MhToolbar
                :search="browser.query.value.search ?? null"
                :sort="browser.query.value.sort ?? 'created_at'"
                :direction="browser.query.value.direction ?? 'desc'"
                :types="browser.query.value.types ?? []"
                @search="browser.search($event)"
                @sort="onSorted"
                @filter="onFiltered"
            />

            <MhQuotaMeter :quota="quota.quota.value" />
        </header>

        <MhSelectionBar
            :selection="selection.asSelection()"
            :actions="actions"
            :client="client"
            @clear="selection.clear()"
            @done="refreshAll"
        />

        <MhDropzone @files="onFiles" />

        <MhUploadQueue
            :items="upload.items.value"
            @abort="upload.abort($event)"
            @retry="upload.retry($event)"
            @clear="upload.clearFinished()"
        />

        <div :class="cls('body')">
            <div :class="cls('main')">
                <MhFolderList :folders="browser.page.value?.folders ?? []" @open="open" />

                <MhItemGrid
                    v-model:selected="chosen"
                    :media="browser.page.value?.media ?? []"
                    multiple
                    :loading="browser.loading.value"
                    :error="browser.error.value"
                    @activate="focused = $event; emit('open', $event)"
                >
                    <template #empty>
                        <MhEmptyState :title="emptyTitle" :description="emptyDescription" />
                    </template>
                </MhItemGrid>
            </div>

            <MhDetailsPanel :media="focused" :client="client" @updated="refreshAll" />
        </div>

        <MhContextMenu
            v-model:open="menu.open"
            :selection="selection.asSelection()"
            :actions="actions"
            :x="menu.x"
            :y="menu.y"
            :client="client"
            @done="refreshAll"
        />
    </section>
</template>
