<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Folder, Media, MediaHubClient, MediaSort, MediaType, Selection } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useMediaBrowser } from '../vue/useMediaBrowser'
import { useQuota } from '../vue/useQuota'
import { useSelection } from '../vue/useSelection'
import { useUpload } from '../vue/useUpload'
import type { MhAction } from './actions'
import MhBreadcrumb from './MhBreadcrumb.vue'
import { GLYPH_BOX, TRASH_GLYPH } from './glyphs'
import MhContextMenu from './MhContextMenu.vue'
import MhDetailsDialog from './MhDetailsDialog.vue'
import MhDropzone from './MhDropzone.vue'
import MhEmptyState from './MhEmptyState.vue'
import MhFolderCreator from './MhFolderCreator.vue'
import MhFolderList from './MhFolderList.vue'
import MhItemGrid from './MhItemGrid.vue'
import MhQuotaMeter from './MhQuotaMeter.vue'
import MhSelectionBar from './MhSelectionBar.vue'
import MhToolbar from './MhToolbar.vue'
import MhUploadButton from './MhUploadButton.vue'
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
        /**
         * ⚠️ WHETHER THIS SCREEN WAS OPENED IN ORDER TO CHOOSE SOMETHING. False by default: a
         * library reached from a menu has no caller, and a button offering to "use" a file there
         * hands it to nobody. Set it, listen to `use`, and the details panel offers the button.
         */
        selectable?: boolean
        ui?: MhComponentOverride
    }>(),
    {
        client: undefined,
        actions: undefined,
        emptyTitle: undefined,
        emptyDescription: undefined,
        selectable: false,
        ui: undefined,
    },
)

const emit = defineEmits<{ open: [media: Media]; use: [media: Media] }>()

const cls = useMediaTheme('mediaLibrary', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    emptyTitle: props.emptyTitle ?? t('library.empty.title'),
    emptyDescription: props.emptyDescription ?? t('library.empty.description'),
}))

const browser = useMediaBrowser(props.client)
const selection = useSelection()
const quota = useQuota(props.client)
const upload = useUpload(props.client)

const focused = ref<Media | null>(null)
const menu = ref({ open: false, x: 0, y: 0 })

/**
 * CHOOSING IS A MODE, AND THE SCREEN DOES ONE THING AT A TIME.
 *
 * ⚠️ A CLICK USED TO MEAN BOTH "show me this" AND "count this in", which is how every file
 * somebody merely looked at ended up in the next batch action — one whose confirmation names a
 * count rather than the files. Splitting them is what lets a tile answer for itself: browsing,
 * the click opens; choosing, it ticks, and nothing else on the tile does anything at all.
 */
const picking = ref(false)

/**
 * ⚠️ THE MENU ACTS ON WHAT IT WAS OPENED ON, NOT ON THE SELECTION. The two used to be the same
 * list, so right-clicking a file quietly ticked it and raised the batch bar — a bar that now
 * belongs to selection mode alone. This holds the one thing the menu was asked about.
 */
const acting = ref<Selection>({})

function stopPicking(): void {
    picking.value = false
    selection.clear()
}

/**
 * THE TRASH, REACHABLE — which it was not from anywhere at all.
 *
 * ⚠️ IT IS A PLACE, NOT A FILTER. Putting it among the kinds — beside "Images" and "Documents" —
 * would say that a trashed image is a sort of image; it is the same file somewhere else, where
 * the only two things you can do to it are put it back and finish the job.
 *
 * ⚠️ AND CROSSING OVER LETS GO OF EVERYTHING. What was ticked on one side means nothing on the
 * other — the actions are not even the same — and a file still shown in the panel would be one
 * the screen behind it no longer lists.
 */
const trashed = computed(() => browser.query.value.trashed === true)

async function toggleTrash(): Promise<void> {
    stopPicking()
    focused.value = null
    menu.value = { open: false, x: 0, y: 0 }

    await browser.showTrashed(!trashed.value)
}

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

/*
 * ⚠️ THREE EMPTINESSES, AND THEY ARE NOT THE SAME SENTENCE. "Nothing here yet" told somebody
 * looking at an empty trash that they had never uploaded anything, and somebody who had just
 * searched that their files were gone.
 */
const emptyTitle = computed(() => {
    if (searching.value) {
        return t('library.noResults.title')
    }

    return trashed.value ? t('library.trash.title') : words.value.emptyTitle
})

const emptyDescription = computed(() => {
    if (searching.value) {
        return t('library.noResults.description')
    }

    return trashed.value ? t('library.trash.description') : words.value.emptyDescription
})

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

/*
 * ⚠️ A FILE THAT LANDED HAS TO APPEAR, AND NOTHING USED TO MAKE IT. The queue reported "done"
 * and the grid went on showing what it had loaded when the screen opened; the only way to see
 * the upload was to reload the page, which reads as the upload having failed.
 */
const landedBefore = ref(0)

watch(
    () => upload.uploading.value,
    async (busy, wasBusy) => {
        /*
         * ⚠️ THE COUNT IS TAKEN WHEN THE BATCH STARTS, not compared against zero. `stored` keeps
         * everything that has ever landed until somebody clears the queue, so "there is
         * something in it" would also be true of a second batch in which every file was refused.
         */
        if (busy) {
            landedBefore.value = upload.stored.value.length

            return
        }

        /*
         * ⚠️ ONE REFRESH PER BATCH, AT THE END — not one per file. Twenty photographs would
         * otherwise ask for the listing twenty times, each answer arriving after the next
         * request went out, and the grid would flicker through twenty intermediate states.
         */
        if (!wasBusy || upload.stored.value.length <= landedBefore.value) {
            return
        }

        await refreshAll()
    },
)

/**
 * THE ACTIONS FOR ONE THING, ASKED FOR ON THAT THING.
 *
 * ⚠️ WHAT IS ALREADY TICKED IS KEPT, AND ONLY REPLACED WHEN THE ITEM IS NOT PART OF IT. Asking
 * for the menu on one of five selected files means "do this to the five"; asking for it on a
 * sixth means "do it to that one". Replacing the selection every time would silently drop four
 * files from an action whose confirmation names a count rather than the files.
 */
function openMenu(on: { media?: Media; folder?: Folder }, event: MouseEvent): void {
    /*
     * ⚠️ NEITHER THE PANEL NOR THE SELECTION IS TOUCHED HERE. Asking what can be done to a file
     * is not asking to look at it, and it is not asking to add it to a batch: a right click that
     * did either would answer a question nobody put — and the second would raise a bar that says
     * "3 selected" over a screen where somebody ticked nothing.
     */
    acting.value = on.media === undefined ? { folders: [on.folder!.id] } : { media: [on.media.id] }

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
    <section :class="cls('root')">
        <header :class="cls('header')">
            <MhToolbar
                :search="browser.query.value.search ?? null"
                :sort="browser.query.value.sort ?? 'created_at'"
                :direction="browser.query.value.direction ?? 'desc'"
                :types="browser.query.value.types ?? []"
                @search="browser.search($event)"
                @sort="onSorted"
                @filter="onFiltered"
            >
                <!-- ⚠️ THE TWO CONTROLS THAT NEED TO KNOW WHERE YOU ARE. Uploading puts files in
                     the folder on screen and creating one puts it under that same folder; a
                     toolbar holding either would have to reach for the browser's state, and stop
                     being a toolbar. It offers the place, this screen fills it. -->
                <template #start>
                    <!-- ⚠️ NOTHING IS ADDED TO A TRASH. Depositing a file there would mean
                         uploading something already thrown away, and a new folder would be born
                         deleted. Both controls were offered anyway, and both would have acted:
                         the upload would have landed in the library behind, out of sight of the
                         screen that accepted it. -->
                    <MhUploadButton v-if="!trashed" @files="onFiles" />

                    <!-- ⚠️ IT SAYS WHICH STATE IT WILL PUT YOU IN, and looks pressed while it is
                         on. Selection mode changes what a click does everywhere on the screen;
                         a control that looks the same either way leaves clicking something and
                         seeing what happens as the only way to find out. -->
                    <!-- ⚠️ A DOOR, NOT A FILTER. Among the kinds it would say that a trashed
                         image is a sort of image; it is the same file somewhere else, where the
                         only two things you can do are put it back and finish the job. -->
                    <button
                        type="button"
                        :class="trashed ? cls('trashOn') : cls('trash')"
                        :aria-pressed="trashed"
                        :aria-label="trashed ? t('toolbar.trashLeave') : t('toolbar.trash')"
                        :title="trashed ? t('toolbar.trashLeave') : t('toolbar.trash')"
                        @click="toggleTrash"
                    >
                        <svg
                            :class="cls('trashIcon')"
                            :viewBox="GLYPH_BOX"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path v-for="(drawing, step) in TRASH_GLYPH" :key="step" :d="drawing" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        :class="picking ? cls('pickingOn') : cls('picking')"
                        :aria-pressed="picking"
                        @click="picking ? stopPicking() : (picking = true)"
                    >
                        {{ picking ? t('toolbar.done') : t('toolbar.select') }}
                    </button>

                    <MhFolderCreator
                        v-if="!trashed"
                        :parent="browser.folder.value"
                        :client="client"
                        @created="refreshAll"
                    />
                </template>
            </MhToolbar>

            <div :class="cls('context')">
                <MhBreadcrumb :trail="browser.breadcrumbs.value" @open="open" />

                <MhQuotaMeter :quota="quota.quota.value" />
            </div>
        </header>

        <!-- ⚠️ IT FOLLOWS THE SELECTION, AND THE SELECTION ONLY EXISTS WHILE CHOOSING. A second
             `v-if` on the mode was there at first and proved to be redundant: nothing outside
             selection mode ticks anything, so the two guards each hid the other's absence and
             removing either changed nothing anybody could see. One rule, held in one place. -->
        <MhSelectionBar
            :selection="selection.asSelection()"
            :actions="actions"
            :trashed="trashed"
            :client="client"
            @clear="selection.clear()"
            @done="refreshAll"
        />

        <MhUploadQueue
            :items="upload.items.value"
            @abort="upload.abort($event)"
            @retry="upload.retry($event)"
            @clear="upload.clearFinished()"
        />

        <div :class="cls('body')">
            <div :class="cls('main')">
                <!-- ⚠️ THE ZONE WRAPS THE LISTING RATHER THAN SITTING ABOVE IT: a file let go
                     over the files — which is where the hand goes — used to be opened by the
                     browser, taking the page with it. The keyboard route is the button in the
                     toolbar, and it is not optional. -->
                <MhDropzone @files="onFiles">
                    <MhFolderList
                        :folders="browser.page.value?.folders ?? []"
                        :picking="picking"
                        :selected="selection.folders.value"
                        @open="open"
                        @toggle="selection.toggle('folder', $event.id)"
                        @menu="(folder, where) => openMenu({ folder }, where)"
                    />

                    <MhItemGrid
                        v-model:selected="chosen"
                        :media="browser.page.value?.media ?? []"
                        multiple
                        :loading="browser.loading.value"
                        :error="browser.error.value"
                        :choosing="picking"
                        @current="focused = $event"
                        @activate="focused = $event; emit('open', $event)"
                        @menu="(chosen, where) => openMenu({ media: chosen }, where)"
                    >
                        <template #empty>
                            <MhEmptyState :title="emptyTitle" :description="emptyDescription" />
                        </template>
                    </MhItemGrid>
                </MhDropzone>
            </div>

        </div>

        <!-- ⚠️ A WINDOW RATHER THAN A COLUMN. The panel is the same component either way; what
             changes is that the grid keeps the width of the screen, and looking at one file no
             longer costs every other file a fifth of the room they had. -->
        <MhDetailsDialog
            :media="focused"
            :client="client"
            :selectable="selectable"
            @updated="refreshAll"
            @use="emit('use', $event)"
            @close="focused = null"
        />

        <MhContextMenu
            v-model:open="menu.open"
            :selection="acting"
            :actions="actions"
            :trashed="trashed"
            :x="menu.x"
            :y="menu.y"
            :client="client"
            @done="refreshAll"
        />
    </section>
</template>
