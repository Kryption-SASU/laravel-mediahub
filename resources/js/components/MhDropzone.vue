<script setup lang="ts">
import { computed, ref } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * DROPPING FILES ONTO WHAT YOU CAN SEE.
 *
 * ⚠️ IT WRAPS THE LISTING RATHER THAN SITTING ABOVE IT. A dashed rectangle parked over the grid
 * accepted a drop on its own few hundred pixels and nowhere else — so a file let go over the
 * files, which is where the hand goes, was opened by the browser and took the page with it. The
 * zone is now the area somebody is actually looking at, and it costs nothing on screen until
 * there is something to catch.
 *
 * ⚠️ AND IT NO LONGER CARRIES THE FILE INPUT. Dragging cannot be done from a keyboard, is
 * awkward with a screen reader and is impossible on most touch devices, so that route must
 * exist — but it is a primary control, not a detail of a drag affordance, and it now lives in
 * `MhUploadButton`, in the toolbar. A screen using this component ALONE has to render one:
 * without it, adding a file becomes something only a mouse can do.
 *
 * ⚠️ THE PAGE MUST NOT NAVIGATE AWAY. A file dropped outside a listener makes the browser open
 * it, replacing the application with a picture and losing whatever was in the form. Both
 * `dragover` and `drop` are cancelled for that reason, not for styling.
 */
const props = withDefaults(
    defineProps<{
        label?: string
        hint?: string
        disabled?: boolean
        ui?: MhComponentOverride
    }>(),
    { label: undefined, hint: undefined, disabled: false, ui: undefined },
)

const emit = defineEmits<{ files: [files: File[]] }>()

const cls = useMediaTheme('dropzone', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    add: props.label ?? t('dropzone.label'),
}))

const over = ref(false)

/**
 * ⚠️ COUNTED RATHER THAN TOGGLED. `dragenter` and `dragleave` fire for every child element the
 * pointer crosses, so a boolean flickers off the moment somebody drags over a tile inside the
 * zone — and the highlight blinks while they are still holding the file. With the listing now
 * inside the zone, that is every few pixels rather than once.
 */
const depth = ref(0)

function onEnter(): void {
    if (props.disabled) {
        return
    }

    depth.value += 1
    over.value = true
}

function onLeave(): void {
    depth.value = Math.max(0, depth.value - 1)
    over.value = depth.value > 0
}

function onDrop(event: DragEvent): void {
    depth.value = 0
    over.value = false

    if (props.disabled) {
        return
    }

    const files = event.dataTransfer?.files ? Array.from(event.dataTransfer.files) : []

    /* ⚠️ AN EMPTY DROP IS NOT AN UPLOAD. Dragging a selection of text, or a link, lands here with
     * no files at all, and reporting it would start a queue with nothing in it. */
    if (files.length > 0) {
        emit('files', files)
    }
}
</script>

<template>
    <div
        :class="over ? cls('active') : cls('root')"
        @dragenter.prevent="onEnter"
        @dragover.prevent
        @dragleave.prevent="onLeave"
        @drop.prevent="onDrop"
    >
        <slot :over="over" />

        <!-- ⚠️ THE VEIL IS RENDERED ONLY WHILE SOMETHING IS BEING HELD, and it never takes the
             pointer: `pointer-events-none` in the theme is what keeps it from standing between
             the cursor and the listener underneath, which would swallow the very drop it is
             announcing. -->
        <div v-if="over" :class="cls('veil')">
            <p :class="cls('label')">{{ words.add }}</p>
            <p v-if="hint" :class="cls('hint')">{{ hint }}</p>
        </div>
    </div>
</template>
