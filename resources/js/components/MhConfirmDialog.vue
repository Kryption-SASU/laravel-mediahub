<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useNativeDialog } from './useNativeDialog'

/**
 * ASKING BEFORE SOMETHING IRREVERSIBLE.
 *
 * ⚠️ A NATIVE `<dialog>`, NOT A DIV WITH A HIGH z-index. `showModal()` brings the focus trap,
 * the Escape key, inertness of everything behind it and the top layer — four things a
 * hand-rolled modal gets wrong in the same four ways every time, and which nobody notices
 * until somebody navigates with a keyboard. It also stops a dialog from being clipped by an
 * ancestor's `overflow: hidden`, which is how modals end up half visible inside a scrolling
 * panel.
 *
 * ⚠️ AND CANCELLING IS THE DEFAULT OUTCOME. Escape, the backdrop, the browser's own dismissal:
 * all of them mean no. A dialog that closes without answering, on a question about deleting
 * files, must never be read as a yes.
 */
const props = withDefaults(
    defineProps<{
        open: boolean
        title: string
        message?: string
        confirmLabel?: string
        cancelLabel?: string
        /**
         * ⚠️ THE LOOK FOLLOWS THE CONSEQUENCE, not the wording. "Empty the trash" is destructive
         * whatever it is called, and the button should say so before it is read.
         */
        destructive?: boolean
        ui?: MhComponentOverride
    }>(),
    {
        message: undefined,
        confirmLabel: undefined,
        cancelLabel: undefined,
        destructive: false,
        ui: undefined,
    },
)

const emit = defineEmits<{ confirm: []; cancel: []; 'update:open': [value: boolean] }>()

const cls = useMediaTheme('confirmDialog', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    confirm: props.confirmLabel ?? t('dialog.confirm'),
    cancel: props.cancelLabel ?? t('dialog.cancel'),
}))

const element = ref<HTMLDialogElement | null>(null)

/*
 * ⚠️ WHO CLOSED IT DECIDES WHAT IT MEANT. Closing the dialog fires `close` whoever asked for it,
 * so a confirmation would emit `confirm` and then `cancel` for the same click — and a caller
 * undoing its own optimistic update on the second event would put the deleted rows back. This
 * flag is what tells an answer apart from a dismissal.
 */
const answered = ref(false)

/*
 * ⚠️ THE FLAG IS CLEARED BY THE HOST OPENING THE DIALOG, NOT BY THE DIALOG APPEARING. Those are
 * two different moments: showing the element is deferred to after the render, so a click
 * answered in between would have its answer wiped by the very effect that opens it — and the
 * closing that follows would then be read as a cancellation. Watching the prop makes the reset
 * happen when the intention is expressed rather than when the DOM catches up.
 */
watch(
    () => props.open,
    (open) => {
        if (open) {
            answered.value = false
        }
    },
    { immediate: true, flush: 'sync' },
)

const confirmClass = computed(() => cls(props.destructive ? 'confirmDestructive' : 'confirm'))

useNativeDialog(element, () => props.open)

function cancel(): void {
    if (answered.value) {
        return
    }

    answered.value = true
    emit('cancel')
    emit('update:open', false)
}

function confirm(): void {
    if (answered.value) {
        return
    }

    answered.value = true
    emit('confirm')
    emit('update:open', false)
}
</script>

<template>
    <!-- ⚠️ `@cancel.prevent` THEN OUR OWN CLOSE: letting the browser close it natively would
         leave `open` true on the host's side, and the next attempt to open it would do nothing
         at all — a dialog that works once. -->
    <dialog
        ref="element"
        :class="cls('root')"
        :aria-label="title"
        @cancel.prevent="cancel"
        @close="cancel"
    >
        <div :class="cls('body')">
            <p :class="cls('title')">{{ title }}</p>
            <p v-if="message" :class="cls('message')">
                <slot>{{ message }}</slot>
            </p>
        </div>

        <div :class="cls('actions')">
            <button type="button" :class="cls('cancel')" @click="cancel">{{ words.cancel }}</button>
            <button type="button" :class="confirmClass" @click="confirm">{{ words.confirm }}</button>
        </div>
    </dialog>
</template>
