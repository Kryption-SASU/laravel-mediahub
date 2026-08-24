<script setup lang="ts">
import type { Folder } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

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
    { label: 'Folders', ui: undefined },
)

defineEmits<{ open: [folder: Folder] }>()

const cls = useMediaTheme('folderList', () => props.ui)
</script>

<template>
    <nav v-if="folders.length > 0" :class="cls('root')" :aria-label="label">
        <ul :class="cls('list')">
            <li v-for="folder in folders" :key="folder.id">
                <button type="button" :class="cls('item')" @click="$emit('open', folder)">
                    <span :class="cls('icon')" aria-hidden="true">/</span>
                    <span :class="cls('name')">{{ folder.name }}</span>
                </button>
            </li>
        </ul>
    </nav>
</template>
