<script setup lang="ts">
import { ref, useId, watch } from 'vue'
import type { Folder, Media, MediaHubClient } from '../client'
import { MediaHubError } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import MhErrorState from './MhErrorState.vue'
import type { MhRenameTarget } from './renaming'
import { renameTo } from './renaming'
import { useNativeDialog } from './useNativeDialog'

/**
 * GIVING ONE THING A DIFFERENT NAME.
 *
 * ⚠️ THE NAME WAS ONLY CHANGEABLE FROM THE DETAILS WINDOW, and a folder's not at all. Renaming is
 * the most ordinary thing anybody does to a file after uploading it, and it sat two clicks deep
 * behind a window somebody opens to read metadata.
 *
 * ⚠️ THE TARGET IS WHAT OPENS IT — no `open` prop beside it, because two ways of saying the same
 * thing drift, and the pair that drifts here is a prompt asking for the new name of nothing.
 *
 * ⚠️ AND THE FIELD STARTS ON THE CURRENT NAME, SELECTED. Renaming is almost always an edit of
 * what is there — a typo, a date, a suffix — and an empty box makes somebody retype a name they
 * were happy with to change one letter of it.
 */
const props = withDefaults(
    defineProps<{
        /** What to rename. `null` closes the prompt — it is the same statement. */
        target: MhRenameTarget | null
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    { client: undefined, ui: undefined },
)

const emit = defineEmits<{ renamed: [item: Media | Folder]; close: [] }>()

const cls = useMediaTheme('renamer', () => props.ui)
const t = useMediaText()
const api = resolveMediaHub(props.client)

const element = ref<HTMLDialogElement | null>(null)
const field = ref<HTMLInputElement | null>(null)
const nameId = useId()
const name = ref('')
const running = ref(false)
const error = ref<MediaHubError | null>(null)

useNativeDialog(element, () => props.target !== null)

watch(
    () => props.target,
    (target) => {
        name.value = target?.name ?? ''
        error.value = null
    },
    { immediate: true },
)

async function submit(): Promise<void> {
    const target = props.target
    const wanted = name.value.trim()

    /* ⚠️ A NAME OF SPACES IS NOT A NAME, and the same name is not a change. Both are refused here
     * rather than at the server, so nothing is spent on a request whose answer is already known. */
    if (target === null || wanted === '' || wanted === target.name || running.value) {
        return
    }

    running.value = true
    error.value = null

    try {
        emit('renamed', await renameTo(api, target, wanted))
        emit('close')
    } catch (failure) {
        /* ⚠️ THE PROMPT STAYS OPEN ON A FAILURE. Closing would take the typed name away with it,
         * and a name refused for being taken is one somebody wants to edit, not retype. */
        error.value =
            failure instanceof MediaHubError
                ? failure
                : new MediaHubError(0, null, 'The name could not be changed.')
    } finally {
        running.value = false
    }
}
</script>

<template>
    <!-- ⚠️ `@cancel.prevent` THEN OUR OWN CLOSE: letting the browser close it natively would leave
         the target on the caller's side, and asking to rename that same thing again would open
         nothing — a prompt that works once. -->
    <dialog
        ref="element"
        :class="cls('root')"
        :aria-label="t('rename.title')"
        @cancel.prevent="emit('close')"
        @close="emit('close')"
    >
        <!-- ⚠️ A FORM, SO ENTER RENAMES. Typing a name and pressing Enter is what everybody does;
             without it the key does nothing, or submits whatever form the dialog sits inside. -->
        <form @submit.prevent="submit">
            <div :class="cls('body')">
                <p :class="cls('title')">{{ t('rename.title') }}</p>

                <label :class="cls('label')" :for="nameId">{{ t('rename.field') }}</label>
                <input
                    :id="nameId"
                    ref="field"
                    v-model="name"
                    :class="cls('input')"
                    type="text"
                    autofocus
                />

                <MhErrorState :error="error" />
            </div>

            <div :class="cls('actions')">
                <button type="button" :class="cls('cancel')" @click="emit('close')">
                    {{ t('rename.cancel') }}
                </button>

                <button
                    type="submit"
                    :class="cls('submit')"
                    :disabled="name.trim() === '' || running"
                >
                    {{ t('rename.save') }}
                </button>
            </div>
        </form>
    </dialog>
</template>
