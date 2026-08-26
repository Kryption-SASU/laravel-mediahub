<script setup lang="ts">
import { computed } from 'vue'
import type { Folder } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { CHECK_GLYPH, FOLDER_GLYPH, GLYPH_BOX, MENU_GLYPH } from './glyphs'

/**
 * THE FOLDERS INSIDE THE ONE BEING LOOKED AT.
 *
 * ⚠️ A LIST, NOT A TREE, AND THAT IS A LIMIT OF THE SERVER RATHER THAN A PREFERENCE. Browsing
 * answers with the children of one folder; drawing a tree would mean a request per branch, or an
 * endpoint that does not exist yet. A component that pretended to be a tree would either be slow
 * in a way nobody could explain, or show a shape that is not the real one.
 *
 * ⚠️ AND THEY ARE OPENED, NOT SELECTED. A folder in the same listbox as the media would have to
 * answer to Space and to Enter in two different ways, and the two would be one keystroke apart.
 */
const props = withDefaults(
    defineProps<{
        folders: readonly Folder[]
        label?: string
        /**
         * ⚠️ WHAT A CLICK MEANS HERE TOO. Browsing, it walks into the folder; choosing, it ticks
         * it — a batch that could act on files but never on the folder holding them would be a
         * rule nobody can see and everybody trips over.
         */
        picking?: boolean
        /** Identifiers of the folders currently ticked. */
        selected?: readonly string[]
        /**
         * The folders something is being done to right now.
         *
         * ⚠️ DRAWN ON THE FOLDER RATHER THAN OVER THE SCREEN. Trashing a folder takes its whole
         * subtree and can be slow; a veil in the middle of the window blocks a library somebody
         * could still be using and says nothing about which folder it is waiting on.
         */
        busy?: readonly string[]
        /** A figure to put beside the mark, when the act can count itself. */
        busyLabel?: string | null
        ui?: MhComponentOverride
    }>(),
    {
        label: undefined,
        picking: false,
        selected: () => [],
        busy: () => [],
        busyLabel: null,
        ui: undefined,
    },
)

const emit = defineEmits<{
    open: [folder: Folder]
    /**
     * ⚠️ A FOLDER HAS ACTIONS TOO, and offering them only on files makes the grid look as though
     * half of it were decoration. Where they were asked for is passed along, because a menu has
     * to open at the pointer rather than in a corner.
     */
    menu: [folder: Folder, event: MouseEvent]
    /** Ticked or unticked, while choosing. */
    toggle: [folder: Folder]
}>()

const cls = useMediaTheme('folderList', () => props.ui)

/* ⚠️ THE TICK FOLLOWS WHAT THE SCREEN HOLDS, not a copy kept here. A second list would go
 * stale the first time somebody cleared the selection from anywhere else. */
const isChosen = (folder: Folder): boolean => props.selected.includes(folder.id)

const isBusy = (folder: Folder): boolean => props.busy.includes(folder.id)

/* ⚠️ BUILT HERE RATHER THAN IN THE MARKUP. A quoted empty string in a `:class` binding is
 * indistinguishable, to the guard that forbids hardcoded classes, from a utility typed in a
 * hurry — and a guard that cries wolf on correct code is one somebody deletes. */
const tileClasses = (folder: Folder): string =>
    [cls('item'), isChosen(folder) ? cls('selected') : ''].join(' ').trim()
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    label: props.label ?? t('folders.label'),
}))
</script>

<template>
    <nav v-if="folders.length > 0" :class="cls('root')" :aria-label="words.label">
        <ul :class="cls('list')">
            <!-- ⚠️ THE MENU IS A SIBLING OF THE TILE, NOT A CHILD OF IT. The tile is a button —
                 opening the folder is what it is for — and a button inside a button is markup no
                 browser agrees on: some drop the inner one, some fire both. -->
            <li v-for="folder in folders" :key="folder.id" :class="cls('entry')">
                <button
                    v-if="!picking && !isBusy(folder)"
                    type="button"
                    tabindex="-1"
                    :class="cls('menu')"
                    :aria-label="t('menu.label')"
                    @click.stop="emit('menu', folder, $event)"
                >
                    <svg
                        :class="cls('menuIcon')"
                        :viewBox="GLYPH_BOX"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path v-for="(drawing, step) in MENU_GLYPH" :key="step" :d="drawing" />
                    </svg>
                </button>

                <!-- ⚠️ THE SAME TILE AS A FILE, ON PURPOSE. A row of pills above a grid of
                     pictures reads as two unrelated things, and the first click somebody makes
                     is on the breadcrumb rather than on the folder they can see. -->
                <button
                    type="button"
                    :class="tileClasses(folder)"
                    :aria-pressed="picking ? isChosen(folder) : undefined"
                    :aria-busy="isBusy(folder) || undefined"
                    :disabled="isBusy(folder)"
                    @click="picking ? emit('toggle', folder) : emit('open', folder)"
                    @contextmenu.prevent.stop="picking || isBusy(folder) || emit('menu', folder, $event)"
                >
                    <!-- ⚠️ THE SAME MARK AS A FILE'S. Two spinners of different shapes on one
                         screen, for one act, read as two different things happening. -->
                    <span v-if="isBusy(folder)" :class="cls('busy')">
                        <svg
                            :class="cls('spinner')"
                            :viewBox="GLYPH_BOX"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" stroke-opacity="0.3" />
                            <path d="M21 12a9 9 0 0 0-9-9" />
                        </svg>

                        <!-- ⚠️ WRITTEN ONLY WHEN THERE IS SOMETHING TO WRITE. An act that
                             cannot count itself leaves this out rather than showing a zero, which
                             would read as a download that has stalled. -->
                        <span v-if="busyLabel" :class="cls('busyLabel')">{{ busyLabel }}</span>
                    </span>

                    <span v-if="isChosen(folder)" :class="cls('tick')" :aria-hidden="true">
                        <svg
                            :class="cls('tickIcon')"
                            :viewBox="GLYPH_BOX"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path v-for="(drawing, step) in CHECK_GLYPH" :key="step" :d="drawing" />
                        </svg>
                    </span>

                    <span :class="cls('preview')">
                        <slot name="icon" :folder="folder">
                            <svg
                                :class="cls('icon')"
                                :viewBox="GLYPH_BOX"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path v-for="(drawing, step) in FOLDER_GLYPH" :key="step" :d="drawing" />
                            </svg>
                        </slot>
                    </span>

                    <span :class="cls('name')" :title="folder.name">{{ folder.name }}</span>
                </button>
            </li>
        </ul>
    </nav>
</template>
