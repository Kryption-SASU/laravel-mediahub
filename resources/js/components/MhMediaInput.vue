<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { Media, MediaHubClient, MediaType } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhMediaPicker from './MhMediaPicker.vue'
import MhThumbnail from './MhThumbnail.vue'

/**
 * ONE MEDIA, IN A FORM.
 *
 * ⚠️ THE MODEL CARRIES THE IDENTIFIER, NOT THE OBJECT. That is what a form posts and what the
 * host stores; modelling it as the whole media would force every screen to unwrap it before
 * saving, and the first one that forgets writes a JSON blob into a foreign key column.
 *
 * ⚠️ AND A HIDDEN FIELD CARRIES IT, so an ordinary Blade form submits with no JavaScript of its
 * own. The alternative — the host wiring a change handler into their own state — is a line
 * everybody has to write and nobody remembers to write twice.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string | null
        /** The field name posted. Absent means no hidden field: the model is enough. */
        name?: string
        /**
         * ⚠️ THE MEDIA ITSELF, WHEN THE HOST ALREADY HAS IT. Without it the field can show an
         * identifier and nothing else until something fetches the rest — which is a form opening
         * on an empty box where a picture used to be.
         */
        media?: Media | null
        types?: readonly MediaType[]
        label?: string
        chooseLabel?: string
        replaceLabel?: string
        clearLabel?: string
        emptyLabel?: string
        disabled?: boolean
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        name: undefined,
        media: null,
        types: () => [],
        label: undefined,
        chooseLabel: undefined,
        replaceLabel: undefined,
        clearLabel: undefined,
        emptyLabel: undefined,
        disabled: false,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{
    'update:modelValue': [id: string | null]
    'update:media': [media: Media | null]
}>()

const cls = useMediaTheme('mediaInput', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    choose: props.chooseLabel ?? t('input.choose'),
    replace: props.replaceLabel ?? t('input.replace'),
    clear: props.clearLabel ?? t('input.clear'),
    empty: props.emptyLabel ?? t('input.empty'),
}))

const picker = ref<InstanceType<typeof MhMediaPicker> | null>(null)
const held = ref<Media | null>(props.media)
const labelId = useId()

/*
 * ⚠️ THE HELD OBJECT FOLLOWS THE MODEL, AND IS DROPPED WHEN THEY DISAGREE. A host clearing the
 * value programmatically, or loading a different record into the same form, would otherwise
 * leave the previous picture on screen beside an empty field — and the person would submit
 * believing they were keeping it.
 */
watch(
    () => props.modelValue,
    (id) => {
        if (id === null || held.value?.id !== id) {
            held.value = id === null ? null : (props.media ?? null)
        }
    },
)

watch(
    () => props.media,
    (media) => {
        if (media && media.id === props.modelValue) {
            held.value = media
        }
    },
    { immediate: true },
)

const chosen = computed<Media | null>(() => (props.modelValue === null ? null : held.value))

async function choose(): Promise<void> {
    const [media] = await (picker.value?.pick({ types: props.types, multiple: false }) ?? [])

    /* ⚠️ A DISMISSAL CHANGES NOTHING. Treating "nothing came back" as "clear it" would erase a
     * chosen file every time somebody opened the picker to look and thought better of it. */
    if (!media) {
        return
    }

    held.value = media
    emit('update:modelValue', media.id)
    emit('update:media', media)
}

function clear(): void {
    held.value = null
    emit('update:modelValue', null)
    emit('update:media', null)
}
</script>

<template>
    <div :class="cls('root')" role="group" :aria-labelledby="label ? labelId : undefined">
        <span v-if="label" :id="labelId" :class="cls('label')">{{ label }}</span>

        <!-- ⚠️ ALWAYS RENDERED, EVEN EMPTY. A field that disappears from the payload when it is
             cleared leaves the server unable to tell "unset it" from "this form never had it". -->
        <input v-if="name" type="hidden" :name="name" :value="modelValue ?? ''" />

        <div :class="cls('preview')">
            <MhThumbnail v-if="chosen" :media="chosen" :alt="null" size="4rem" />
            <span v-else :class="cls('empty')">{{ words.empty }}</span>

            <span v-if="chosen" :class="cls('name')">{{ chosen.name }}</span>
        </div>

        <div :class="cls('actions')">
            <button type="button" :class="cls('choose')" :disabled="disabled" @click="choose">
                {{ chosen ? words.replace : words.choose }}
            </button>

            <button
                v-if="chosen"
                type="button"
                :class="cls('clear')"
                :disabled="disabled"
                @click="clear"
            >
                {{ words.clear }}
            </button>
        </div>

        <MhMediaPicker ref="picker" :client="client" />
    </div>
</template>
