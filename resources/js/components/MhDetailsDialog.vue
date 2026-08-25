<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Media, MediaHubClient } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhDetailsPanel from './MhDetailsPanel.vue'
import { CLOSE_GLYPH, GLYPH_BOX } from './glyphs'
import { useNativeDialog } from './useNativeDialog'

/**
 * THE DETAILS OF ONE FILE, IN A WINDOW OF THEIR OWN.
 *
 * ⚠️ IT WRAPS `MhDetailsPanel` RATHER THAN REPLACING IT. A panel in a column is a perfectly good
 * shape — it is what an application with room to spare should use — and a component that could
 * only ever be modal would take that choice away from every host. The panel holds what is shown;
 * this holds where it is shown.
 *
 * ⚠️ AND THE FILE IS WHAT OPENS IT. There is no `open` prop beside `media`: two ways of saying
 * the same thing drift, and the pair that drifts here is a window showing the details of nothing.
 */
const props = withDefaults(
    defineProps<{
        /** The file to show. `null` closes the window — it is the same statement. */
        media: Media | null
        client?: MediaHubClient
        selectable?: boolean
        closeLabel?: string
        ui?: MhComponentOverride
    }>(),
    { client: undefined, selectable: false, closeLabel: undefined, ui: undefined },
)

const emit = defineEmits<{
    updated: [media: Media]
    use: [media: Media]
    /** Somebody asked to stop looking. The screen decides what that means for its state. */
    close: []
}>()

const cls = useMediaTheme('detailsDialog', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    close: props.closeLabel ?? t('details.close'),
}))

const element = ref<HTMLDialogElement | null>(null)

useNativeDialog(element, () => props.media !== null)

/**
 * ⚠️ THE BACKDROP IS PART OF THE DIALOG ELEMENT, NOT A SEPARATE LAYER. A click on it therefore
 * arrives here with the dialog itself as its target, and that is the only way to tell it apart
 * from a click on the contents — which is why this compares identity rather than using
 * `contains()`, that would answer true for both.
 */
function onClick(event: MouseEvent): void {
    if (event.target === element.value) {
        emit('close')
    }
}
</script>

<template>
    <!-- ⚠️ `@cancel.prevent` THEN OUR OWN CLOSE: letting the browser close it natively would leave
         the file on the caller's side, and the next click on that same file would open nothing at
         all — a window that works once. -->
    <dialog
        ref="element"
        :class="cls('root')"
        :aria-label="media?.name"
        @cancel.prevent="emit('close')"
        @close="emit('close')"
        @click="onClick"
    >
        <div v-if="media" :class="cls('body')">
            <div :class="cls('header')">
                <p :class="cls('title')" :title="media.name">{{ media.name }}</p>

                <button
                    type="button"
                    :class="cls('close')"
                    :aria-label="words.close"
                    @click="emit('close')"
                >
                    <svg
                        :class="cls('closeIcon')"
                        :viewBox="GLYPH_BOX"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path v-for="(drawing, step) in CLOSE_GLYPH" :key="step" :d="drawing" />
                    </svg>
                </button>
            </div>

            <!-- ⚠️ RENDERED ONLY WITH A FILE IN HAND. The panel has a resting state of its own,
                 for the column it was written for; inside a window, "nothing selected" is a
                 window that should not have opened. -->
            <MhDetailsPanel
                :media="media"
                :client="client"
                :selectable="selectable"
                :ui="{ root: { class: cls('panel') } }"
                @updated="emit('updated', $event)"
                @use="emit('use', $event)"
            />
        </div>
    </dialog>
</template>
