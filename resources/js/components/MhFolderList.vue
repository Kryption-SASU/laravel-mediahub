<script setup lang="ts">
import { computed } from 'vue'
import type { Folder } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { FOLDER_GLYPH, GLYPH_BOX } from './glyphs'

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
        ui?: MhComponentOverride
    }>(),
    { label: undefined, ui: undefined },
)

defineEmits<{ open: [folder: Folder] }>()

const cls = useMediaTheme('folderList', () => props.ui)
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
            <li v-for="folder in folders" :key="folder.id">
                <!-- ⚠️ THE SAME TILE AS A FILE, ON PURPOSE. A row of pills above a grid of
                     pictures reads as two unrelated things, and the first click somebody makes
                     is on the breadcrumb rather than on the folder they can see. -->
                <button type="button" :class="cls('item')" @click="$emit('open', folder)">
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
