<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { Media, MediaHubClient } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useMediaActions } from '../vue/useMediaActions'
import MhErrorState from './MhErrorState.vue'
import MhThumbnail from './MhThumbnail.vue'

/**
 * ONE FILE, IN FULL — AND THE TWO THINGS WORTH CHANGING ABOUT IT.
 *
 * ⚠️ THE ALTERNATIVE TEXT IS A FIELD HERE, NOT AN AFTERTHOUGHT. It is the only place in a media
 * library where somebody can write it, and a library that never asks produces a site where every
 * picture is silent. It sits beside the name, at the same size, for that reason.
 *
 * ⚠️ THE FORM IS NOT THE MEDIA. Editing writes into local state and only reaches the server when
 * somebody saves: binding the model directly would send a request per keystroke, and would leave
 * a half-typed name on the record of anyone who changed their mind.
 */
const props = withDefaults(
    defineProps<{
        media: Media | null
        nameLabel?: string
        altLabel?: string
        saveLabel?: string
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        nameLabel: undefined,
        altLabel: undefined,
        saveLabel: undefined,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{ updated: [media: Media] }>()

const cls = useMediaTheme('detailsPanel', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    name: props.nameLabel ?? t('details.name'),
    alt: props.altLabel ?? t('details.alt'),
    save: props.saveLabel ?? t('details.save'),
}))

const actions = useMediaActions(props.client)

const nameId = useId()
const altId = useId()

const name = ref('')
const alt = ref('')

/*
 * ⚠️ THE FIELDS FOLLOW THE FILE, and are reset when it changes. Clicking from one picture to the
 * next while a name is half-typed would otherwise carry that text onto the second one — and the
 * first save would rename the wrong file.
 */
watch(
    () => props.media,
    (media) => {
        name.value = media?.name ?? ''

        const declared = media?.custom_properties['alt']

        alt.value = typeof declared === 'string' ? declared : ''
    },
    { immediate: true },
)

const dirty = computed(() => {
    const media = props.media

    if (!media) {
        return false
    }

    const declared = media.custom_properties['alt']
    const currentAlt = typeof declared === 'string' ? declared : ''

    return name.value !== media.name || alt.value !== currentAlt
})

/** ⚠️ BYTES ARE NOT A SIZE ANYBODY READS. See the quota meter for the same reasoning. */
function readable(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB']

    let value = Math.max(0, bytes)
    let unit = 0

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024
        unit += 1
    }

    const rounded =
        value >= 10 || unit === 0 ? String(Math.round(value)) : value.toFixed(1).replace(/\.0$/, '')

    return rounded + ' ' + units[unit]
}

const dimensions = computed(() => {
    const media = props.media

    return media?.width && media.height ? media.width + ' × ' + media.height : null
})

async function save(): Promise<void> {
    const media = props.media

    if (!media) {
        return
    }

    /*
     * ⚠️ TWO CALLS, AND ONLY THE ONES THAT CHANGED. Sending the properties along with every
     * rename would overwrite an alternative text somebody else edited in the meantime, and the
     * loss would be invisible: nothing failed, the field simply went back to what this screen
     * happened to be holding.
     */
    let updated = media

    if (name.value !== media.name) {
        updated = await actions.rename(updated, name.value)
    }

    const declared = media.custom_properties['alt']

    if (alt.value !== (typeof declared === 'string' ? declared : '')) {
        updated = await actions.annotate(updated, { ...updated.custom_properties, alt: alt.value })
    }

    emit('updated', updated)
}
</script>

<template>
    <aside v-if="media" :class="cls('root')" :aria-label="media.name">
        <MhThumbnail :media="media" :alt="null" size="8rem" />

        <dl :class="cls('facts')">
            <div :class="cls('fact')">
                <dt :class="cls('term')">{{ t('details.type') }}</dt>
                <dd :class="cls('value')">{{ media.mime_type }}</dd>
            </div>

            <div :class="cls('fact')">
                <dt :class="cls('term')">{{ t('details.size') }}</dt>
                <dd :class="cls('value')">{{ readable(media.size) }}</dd>
            </div>

            <div v-if="dimensions" :class="cls('fact')">
                <dt :class="cls('term')">{{ t('details.dimensions') }}</dt>
                <dd :class="cls('value')">{{ dimensions }}</dd>
            </div>
        </dl>

        <div :class="cls('field')">
            <label :class="cls('label')" :for="nameId">{{ words.name }}</label>
            <input :id="nameId" v-model="name" :class="cls('input')" type="text" />
        </div>

        <div :class="cls('field')">
            <label :class="cls('label')" :for="altId">{{ words.alt }}</label>
            <input :id="altId" v-model="alt" :class="cls('input')" type="text" />
        </div>

        <!-- ⚠️ DISABLED WHILE NOTHING CHANGED. A save button that is always live invites a
             request that rewrites a record with exactly what it already held. -->
        <button
            type="button"
            :class="cls('save')"
            :disabled="!dirty || actions.running.value"
            @click="save"
        >
            {{ words.save }}
        </button>

        <MhErrorState :error="actions.error.value" />
    </aside>
</template>
