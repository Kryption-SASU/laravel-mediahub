<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import type { Folder, MediaHubClient } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useFolders } from '../vue/useFolders'
import MhErrorState from './MhErrorState.vue'
import { FOLDER_ADD_GLYPH, GLYPH_BOX } from './glyphs'
import { useNativeDialog } from './useNativeDialog'

/**
 * MAKING A FOLDER, FROM THE ONE BEING LOOKED AT.
 *
 * ⚠️ THE PARENT IS A PROP, NOT SOMETHING THIS COMPONENT GOES AND FINDS. A folder created from
 * inside "Clients/Acme" that lands at the root is not a small mistake: it is invisible until
 * somebody goes looking for it, and by then files have been put in it. The screen that knows
 * where you are says so, and this component does exactly what it is told.
 *
 * ⚠️ AND A NATIVE `<dialog>`, LIKE EVERY OTHER PROMPT HERE. `showModal()` brings the focus trap,
 * the Escape key, inertness of what is behind and the top layer — four things a hand-rolled
 * modal gets wrong in the same four ways every time.
 */
const props = withDefaults(
    defineProps<{
        /** Where the new folder goes. `null` is the root, and it has to be said. */
        parent?: Folder | null
        client?: MediaHubClient
        label?: string
        title?: string
        nameLabel?: string
        submitLabel?: string
        cancelLabel?: string
        ui?: MhComponentOverride
    }>(),
    {
        parent: null,
        client: undefined,
        label: undefined,
        title: undefined,
        nameLabel: undefined,
        submitLabel: undefined,
        cancelLabel: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{ created: [folder: Folder] }>()

const cls = useMediaTheme('folderCreator', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    trigger: props.label ?? t('folders.create'),
    title: props.title ?? t('folders.create.title'),
    name: props.nameLabel ?? t('folders.create.name'),
    submit: props.submitLabel ?? t('folders.create.submit'),
    cancel: props.cancelLabel ?? t('folders.create.cancel'),
}))

const folders = useFolders(props.client)

const element = ref<HTMLDialogElement | null>(null)
const nameId = useId()
const open = ref(false)
const name = ref('')

useNativeDialog(element, () => open.value)

/* ⚠️ A NAME OF SPACES IS NOT A NAME. Trimmed here rather than at the server, so the button is
 * refused before the request rather than after it. */
const named = computed(() => name.value.trim())

function show(): void {
    name.value = ''
    open.value = true
}

function close(): void {
    open.value = false
}

async function submit(): Promise<void> {
    if (named.value === '' || folders.running.value) {
        return
    }

    try {
        emit('created', await folders.create(named.value, props.parent ?? null))
        close()
    } catch {
        /* ⚠️ THE DIALOG STAYS OPEN, AND THE ERROR IS ALREADY HELD BY THE COMPOSABLE. Closing on
         * a failure would take the typed name away with it, and rethrowing would raise an
         * unhandled rejection for a message that is on screen a line below. */
    }
}
</script>

<template>
    <span :class="cls('root')">
        <button type="button" :class="cls('trigger')" @click="show">
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
                    <path v-for="(drawing, step) in FOLDER_ADD_GLYPH" :key="step" :d="drawing" />
                </svg>
            </slot>

            {{ words.trigger }}
        </button>

        <!-- ⚠️ `@cancel.prevent` THEN OUR OWN CLOSE: letting the browser close it natively would
             leave our own flag true, and the next click on the trigger would do nothing at all —
             a dialog that works once. -->
        <dialog
            ref="element"
            :class="cls('dialog')"
            :aria-label="words.title"
            @cancel.prevent="close"
            @close="close"
        >
            <!-- ⚠️ A FORM, SO ENTER CREATES. Typing a name and pressing Enter is what everybody
                 does; without it the key does nothing, or submits whatever form the dialog
                 happens to sit inside. -->
            <form @submit.prevent="submit">
                <div :class="cls('body')">
                    <p :class="cls('title')">{{ words.title }}</p>

                    <label :class="cls('label')" :for="nameId">{{ words.name }}</label>
                    <input :id="nameId" v-model="name" :class="cls('input')" type="text" />

                    <MhErrorState :error="folders.error.value" />
                </div>

                <div :class="cls('actions')">
                    <button type="button" :class="cls('cancel')" @click="close">
                        {{ words.cancel }}
                    </button>

                    <button
                        type="submit"
                        :class="cls('submit')"
                        :disabled="named === '' || folders.running.value"
                    >
                        {{ words.submit }}
                    </button>
                </div>
            </form>
        </dialog>
    </span>
</template>
