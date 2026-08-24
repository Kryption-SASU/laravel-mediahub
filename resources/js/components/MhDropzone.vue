<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * DROPPING FILES — AND, JUST AS IMPORTANTLY, CHOOSING THEM.
 *
 * ⚠️ A DROPZONE WITHOUT A FILE INPUT IS UNUSABLE BY A THIRD OF THE PEOPLE WHO NEED IT. Dragging
 * cannot be done from a keyboard, is awkward with a screen reader and is impossible on most
 * touch devices. The real `<input type="file">` below is not a fallback bolted on for
 * completeness: it is the only route some people have, so it is a labelled control rather than
 * a hidden one triggered by a click handler.
 *
 * ⚠️ AND THE PAGE MUST NOT NAVIGATE AWAY. A file dropped outside a listener makes the browser
 * open it, replacing the application with a picture and losing whatever was in the form. Both
 * `dragover` and `drop` are cancelled for that reason, not for styling.
 */
const props = withDefaults(
    defineProps<{
        label?: string
        hint?: string
        accept?: string
        disabled?: boolean
        ui?: MhComponentOverride
    }>(),
    { label: undefined, hint: undefined, accept: undefined, disabled: false, ui: undefined },
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

const inputId = useId()
const over = ref(false)

/**
 * ⚠️ COUNTED RATHER THAN TOGGLED. `dragenter` and `dragleave` fire for every child element the
 * pointer crosses, so a boolean flickers off the moment somebody drags over the label inside the
 * zone — and the highlight blinks while they are still holding the file.
 */
const depth = ref(0)

function accepted(list: FileList | null | undefined): File[] {
    return list ? Array.from(list) : []
}

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

    const files = accepted(event.dataTransfer?.files)

    /* ⚠️ AN EMPTY DROP IS NOT AN UPLOAD. Dragging a selection of text, or a link, lands here with
     * no files at all, and reporting it would start a queue with nothing in it. */
    if (files.length > 0) {
        emit('files', files)
    }
}

function onChosen(event: Event): void {
    const input = event.target as HTMLInputElement
    const files = accepted(input.files)

    if (files.length > 0) {
        emit('files', files)
    }

    /*
     * ⚠️ THE INPUT IS EMPTIED AFTERWARDS. Choosing the same file twice in a row fires no `change`
     * event otherwise — the value has not changed — and the second attempt does nothing, which
     * reads as the button being broken.
     */
    input.value = ''
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
        <label :class="cls('label')" :for="inputId">{{ words.add }}</label>

        <input
            :id="inputId"
            :class="cls('input')"
            type="file"
            multiple
            :accept="accept"
            :disabled="disabled"
            @change="onChosen"
        />

        <p v-if="hint" :class="cls('hint')">{{ hint }}</p>

        <slot :over="over" />
    </div>
</template>
