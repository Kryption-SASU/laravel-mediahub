<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { Media, MediaHubClient, MediaType } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhMediaPicker from './MhMediaPicker.vue'
import MhThumbnail from './MhThumbnail.vue'

/**
 * SEVERAL MEDIA, IN A FORM, IN AN ORDER SOMEBODY CHOSE.
 *
 * ⚠️ THE ORDER IS THE VALUE. A gallery whose order is decorative is a gallery that reshuffles
 * itself on the next page load, and the person re-does the work every time. The model is a list
 * of identifiers, and its order is what gets posted.
 *
 * ⚠️ REORDERING IS BUTTONS, NOT DRAGGING — for now, and deliberately. Drag and drop cannot be
 * operated from a keyboard, is awkward on a touch screen and is untestable without a real
 * browser; shipping it first would mean shipping a gallery a portion of users simply cannot
 * reorder. Dragging can be added on top of this later; the reverse could not.
 */
const props = withDefaults(
    defineProps<{
        modelValue: readonly string[]
        /**
         * Posted as `name` per item, in order.
         *
         * ⚠️ PASS IT WITH BRACKETS — `gallery_ids[]` — or a classic form keeps only the last one,
         * and a gallery of six saves as one.
         */
        name?: string
        /** The media themselves, when the host already has them. */
        media?: readonly Media[]
        types?: readonly MediaType[]
        label?: string
        addLabel?: string
        removeLabel?: string
        moveUpLabel?: string
        moveDownLabel?: string
        emptyLabel?: string
        max?: number
        disabled?: boolean
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        name: undefined,
        media: () => [],
        types: () => [],
        label: undefined,
        addLabel: undefined,
        removeLabel: undefined,
        moveUpLabel: undefined,
        moveDownLabel: undefined,
        emptyLabel: undefined,
        max: undefined,
        disabled: false,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{
    'update:modelValue': [ids: string[]]
    'update:media': [media: Media[]]
}>()

const cls = useMediaTheme('mediaGallery', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    add: props.addLabel ?? t('gallery.add'),
    remove: props.removeLabel ?? t('gallery.remove'),
    moveUp: props.moveUpLabel ?? t('gallery.moveUp'),
    moveDown: props.moveDownLabel ?? t('gallery.moveDown'),
    empty: props.emptyLabel ?? t('gallery.empty'),
}))

const picker = ref<InstanceType<typeof MhMediaPicker> | null>(null)
const labelId = useId()

/*
 * ⚠️ EVERY MEDIA EVER SEEN IS REMEMBERED, and never dropped when it leaves the list. Someone who
 * removes a picture and puts it back would otherwise get a blank tile: the object came from a
 * picker page that has since been replaced, and nothing would fetch it again.
 */
const known = ref(new Map<string, Media>())

watch(
    () => props.media,
    (media) => {
        for (const item of media) {
            known.value.set(item.id, item)
        }
    },
    { immediate: true, deep: true },
)

const items = computed<Array<{ id: string; media: Media | null }>>(() =>
    props.modelValue.map((id) => ({ id, media: known.value.get(id) ?? null })),
)

const full = computed(() => props.max !== undefined && props.modelValue.length >= props.max)

function commit(ids: string[]): void {
    emit('update:modelValue', ids)
    emit(
        'update:media',
        ids.map((id) => known.value.get(id)).filter((item): item is Media => item !== undefined),
    )
}

async function add(): Promise<void> {
    const chosen = await (picker.value?.pick({ types: props.types, multiple: true }) ?? [])

    if (chosen.length === 0) {
        return
    }

    for (const item of chosen) {
        known.value.set(item.id, item)
    }

    /*
     * ⚠️ ADDING THE SAME FILE TWICE IS A MISTAKE, NOT A FEATURE. Two identical tiles in a gallery
     * look like a rendering fault, and the duplicate survives every save.
     */
    const merged = [...props.modelValue]

    for (const item of chosen) {
        if (!merged.includes(item.id)) {
            merged.push(item.id)
        }
    }

    commit(props.max === undefined ? merged : merged.slice(0, props.max))
}

function remove(id: string): void {
    commit(props.modelValue.filter((current) => current !== id))
}

function move(index: number, by: number): void {
    const target = index + by
    const ids = [...props.modelValue]

    /* ⚠️ A MOVE OFF EITHER END IS A NO-OP, not a wrap-around: the first item jumping to the last
     * position reads as the list having been shuffled by something else. */
    if (target < 0 || target >= ids.length) {
        return
    }

    const [moved] = ids.splice(index, 1)

    if (moved === undefined) {
        return
    }

    ids.splice(target, 0, moved)
    commit(ids)
}
</script>

<template>
    <div :class="cls('root')" role="group" :aria-labelledby="label ? labelId : undefined">
        <span v-if="label" :id="labelId" :class="cls('label')">{{ label }}</span>

        <p v-if="items.length === 0" :class="cls('empty')">{{ words.empty }}</p>

        <ol v-else :class="cls('list')">
            <li v-for="(item, index) in items" :key="item.id" :class="cls('item')">
                <input v-if="name" type="hidden" :name="name" :value="item.id" />

                <MhThumbnail v-if="item.media" :media="item.media" :alt="null" size="4rem" />
                <span v-else :class="cls('unknown')" aria-hidden="true" />

                <span :class="cls('name')">{{ item.media?.name ?? item.id }}</span>

                <!-- ⚠️ EACH BUTTON NAMES ITS OWN ITEM. Six buttons all announced as "Move earlier"
                     tell a screen reader user nothing about which picture they are about to move. -->
                <button
                    type="button"
                    :class="cls('moveUp')"
                    :disabled="disabled || index === 0"
                    :aria-label="`${words.moveUp}: ${item.media?.name ?? item.id}`"
                    @click="move(index, -1)"
                >
                    {{ words.moveUp }}
                </button>

                <button
                    type="button"
                    :class="cls('moveDown')"
                    :disabled="disabled || index === items.length - 1"
                    :aria-label="`${words.moveDown}: ${item.media?.name ?? item.id}`"
                    @click="move(index, 1)"
                >
                    {{ words.moveDown }}
                </button>

                <button
                    type="button"
                    :class="cls('remove')"
                    :disabled="disabled"
                    :aria-label="`${words.remove}: ${item.media?.name ?? item.id}`"
                    @click="remove(item.id)"
                >
                    {{ words.remove }}
                </button>
            </li>
        </ol>

        <button type="button" :class="cls('add')" :disabled="disabled || full" @click="add">
            {{ words.add }}
        </button>

        <MhMediaPicker ref="picker" :client="client" />
    </div>
</template>
