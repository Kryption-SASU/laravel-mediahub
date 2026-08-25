<script setup lang="ts">
import { computed, useId } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { GLYPH_BOX, UPLOAD_GLYPH } from './glyphs'

/**
 * CHOOSING FILES — THE ROUTE THAT DOES NOT NEED A MOUSE.
 *
 * ⚠️ THIS IS THE PRIMARY CONTROL, NOT A FALLBACK BOLTED ONTO THE DROP ZONE. Dragging cannot be
 * done from a keyboard, is awkward with a screen reader and is impossible on most touch devices.
 * It used to live inside the dashed rectangle, as a bare `<input type="file">` in the middle of
 * the page — present, but reading as debris rather than as the way to add a file. Pulled out, it
 * sits in the toolbar where a primary action is looked for, and the drop zone goes back to being
 * what it is: a shortcut for people holding a file.
 *
 * ⚠️ THE INPUT IS REAL AND STILL IN THE TAB ORDER. `sr-only` in the theme moves it out of sight,
 * not out of the accessibility tree: it keeps its label, its focus and its keyboard operation.
 * A click handler on a `hidden` input would take all three away and leave a control only a mouse
 * can reach — which is the exact failure this component exists to avoid.
 */
const props = withDefaults(
    defineProps<{
        label?: string
        /** Passed straight to the input. Narrowing here does not enforce anything server-side. */
        accept?: string
        multiple?: boolean
        disabled?: boolean
        ui?: MhComponentOverride
    }>(),
    { label: undefined, accept: undefined, multiple: true, disabled: false, ui: undefined },
)

const emit = defineEmits<{ files: [files: File[]] }>()

const cls = useMediaTheme('uploadButton', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    label: props.label ?? t('upload.label'),
}))

const inputId = useId()

function onChosen(event: Event): void {
    const input = event.target as HTMLInputElement
    const files = input.files ? Array.from(input.files) : []

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
    <span :class="cls('root')">
        <label :class="cls('label')" :for="inputId">
            <slot name="icon">
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
                    <path v-for="(drawing, step) in UPLOAD_GLYPH" :key="step" :d="drawing" />
                </svg>
            </slot>

            {{ words.label }}
        </label>

        <input
            :id="inputId"
            :class="cls('input')"
            type="file"
            :multiple="multiple"
            :accept="accept"
            :disabled="disabled"
            @change="onChosen"
        />
    </span>
</template>
