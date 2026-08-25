<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import type { Media, MediaHubClient } from '../client'
import { intlLocale, useMediaLocale, useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { useMediaActions } from '../vue/useMediaActions'
import MhErrorState from './MhErrorState.vue'
import { CHECK_GLYPH, COPY_GLYPH, GLYPH_BOX } from './glyphs'
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
        useLabel?: string
        /**
         * ⚠️ WHETHER SOMEBODY IS WAITING FOR AN ANSWER — and it is false by default. A library
         * opened from a menu has no caller: a button offering to "use" a file there hands it to
         * nobody, and the click does nothing at all. It appears when a field opened the library,
         * which is the only moment the word means something.
         */
        selectable?: boolean
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    {
        nameLabel: undefined,
        altLabel: undefined,
        saveLabel: undefined,
        useLabel: undefined,
        selectable: false,
        client: undefined,
        ui: undefined,
    },
)

const emit = defineEmits<{ updated: [media: Media]; use: [media: Media] }>()

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
    use: props.useLabel ?? t('details.use'),
    empty: t('details.empty'),
    emptyHint: t('details.emptyHint'),
    url: t('details.url'),
    copy: t('details.copy'),
    copied: t('details.copied'),
    created: t('details.created'),
    updated: t('details.updated'),
    orientation: t('details.orientation'),
}))

const actions = useMediaActions(props.client)

const nameId = useId()
const altId = useId()

const urlId = useId()

const name = ref('')
const alt = ref('')

/* ⚠️ DECLARED BEFORE THE WATCH THAT CLEARS IT. The watch below runs immediately, during setup:
 * a `const` declared after it is still in its dead zone at that moment, and the panel throws
 * before it renders anything. */
const link = ref<HTMLInputElement | null>(null)
const copied = ref(false)

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

        /* ⚠️ AND THE CONFIRMATION GOES WITH THE FILE IT BELONGED TO. "Copied" left standing over
         * the next file's address claims something nobody did. */
        copied.value = false

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

const size = computed<{ width: number; height: number } | null>(() =>
    props.media?.width && props.media.height
        ? { width: props.media.width, height: props.media.height }
        : null,
)

const dimensions = computed(() => {
    const measured = size.value

    return measured === null ? null : measured.width + ' × ' + measured.height
})

/**
 * ⚠️ SAID IN A WORD RATHER THAN LEFT TO BE WORKED OUT. "1920 × 1080" is a fact somebody has to
 * compare two halves of; "landscape" is the answer they were after. A square is neither of the
 * other two and saying so is not pedantry — it is the case where guessing from a glance fails.
 */
const orientation = computed<string | null>(() => {
    const measured = size.value

    if (measured === null) {
        return null
    }

    if (measured.width === measured.height) {
        return t('details.square')
    }

    return measured.width > measured.height ? t('details.landscape') : t('details.portrait')
})

/*
 * ⚠️ THE MOMENTS ARE WRITTEN IN THE ORGANISATION'S LANGUAGE, not the browser's. A back-office
 * whose every other screen speaks French should not put "Aug 12, 2026" in the one panel that
 * shows a date; the tag comes from the provider, the shape from `Intl`.
 */
const locale = useMediaLocale()

const moments = computed(() => {
    const format = new Intl.DateTimeFormat(intlLocale(locale()), {
        dateStyle: 'medium',
        timeStyle: 'short',
    })

    /* ⚠️ A DATE THE SERVER LEFT NULL, OR ONE `Date` CANNOT PARSE, IS ABSENT — not "Invalid
     * Date" printed where a date belongs, which reads as data we have lost rather than data we
     * were never given. */
    const at = (value: string | null): string | null => {
        if (!value) {
            return null
        }

        const moment = new Date(value)

        return Number.isNaN(moment.getTime()) ? null : format.format(moment)
    }

    return {
        created: at(props.media?.created_at ?? null),
        updated: at(props.media?.updated_at ?? null),
    }
})

let clearing: ReturnType<typeof setTimeout> | null = null

/* ⚠️ THE TIMER OUTLIVES THE COMPONENT OTHERWISE. Clicking copy and then leaving the screen
 * leaves a callback writing into a ref nothing renders any more. */
onBeforeUnmount(() => {
    if (clearing !== null) {
        clearTimeout(clearing)
    }
})

/**
 * COPYING THE ADDRESS — AND THE FIELD IS SELECTED WHATEVER HAPPENS.
 *
 * ⚠️ `navigator.clipboard` DOES NOT EXIST OUTSIDE A SECURE CONTEXT, which is to say on every
 * `http://` development host there is. A button relying on it alone does nothing there, silently,
 * and the first report is "the copy button is broken" from the one environment everybody works
 * in. Selecting the text first leaves a keyboard route that always works; the API and the legacy
 * command are then tried in turn, and "Copied" is only ever shown when one of them said yes.
 */
async function copy(): Promise<void> {
    const url = props.media?.url

    if (!url) {
        return
    }

    link.value?.select()

    let done = false

    try {
        await navigator.clipboard?.writeText(url)
        done = navigator.clipboard !== undefined
    } catch {
        /* Refused by permission or by the document not being focused. The command below is the
         * remaining route, and the selection is the one after that. */
    }

    if (!done && typeof document.execCommand === 'function') {
        done = document.execCommand('copy')
    }

    copied.value = done

    if (clearing !== null) {
        clearTimeout(clearing)
    }

    clearing = setTimeout(() => {
        copied.value = false
    }, 2000)
}

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
        <!-- ⚠️ AS BIG AS THE PANEL IS WIDE. A details panel exists to let somebody be sure they
             picked the right file, and a 3rem chip beside four lines of metadata does not settle
             that for two screenshots taken a minute apart. -->
        <span :class="cls('preview')">
            <MhThumbnail :media="media" :alt="null" size="100%" />
        </span>

        <!-- ⚠️ THE ADDRESS IS A FIELD, NOT A LINE OF TEXT, and that is the whole point: a
             `<span>` cannot be selected reliably, and the copy button is not available on an
             insecure origin. Read-only and selectable, it always leaves a way to take it. -->
        <div :class="cls('field')">
            <label :class="cls('label')" :for="urlId">{{ words.url }}</label>

            <div :class="cls('link')">
                <input
                    :id="urlId"
                    ref="link"
                    :class="cls('linkInput')"
                    type="text"
                    readonly
                    :value="media.url"
                />

                <button
                    type="button"
                    :class="copied ? cls('copied') : cls('copy')"
                    :aria-label="copied ? words.copied : words.copy"
                    :title="copied ? words.copied : words.copy"
                    @click="copy"
                >
                    <svg
                        :class="cls('copyIcon')"
                        :viewBox="GLYPH_BOX"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path
                            v-for="(drawing, step) in copied ? CHECK_GLYPH : COPY_GLYPH"
                            :key="step"
                            :d="drawing"
                        />
                    </svg>
                </button>
            </div>
        </div>

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

            <div v-if="orientation" :class="cls('fact')">
                <dt :class="cls('term')">{{ words.orientation }}</dt>
                <dd :class="cls('value')">{{ orientation }}</dd>
            </div>

            <!-- ⚠️ A MOMENT THE SERVER DID NOT GIVE IS ABSENT, not an empty row: a term with
                 nothing under it reads as a fact we have lost. -->
            <div v-if="moments.created" :class="cls('fact')">
                <dt :class="cls('term')">{{ words.created }}</dt>
                <dd :class="cls('value')">{{ moments.created }}</dd>
            </div>

            <div v-if="moments.updated" :class="cls('fact')">
                <dt :class="cls('term')">{{ words.updated }}</dt>
                <dd :class="cls('value')">{{ moments.updated }}</dd>
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

        <!-- ⚠️ ONLY WHEN SOMEBODY IS WAITING FOR AN ANSWER. A library opened from a menu has no
             caller: this button would hand the file to nobody, and the click would do nothing at
             all. It is the same rule the old library applies — its own insert button is hidden
             unless the panel was opened from a field. -->
        <button
            v-if="selectable"
            type="button"
            :class="cls('use')"
            @click="emit('use', media)"
        >
            {{ words.use }}
        </button>

        <MhErrorState :error="actions.error.value" />
    </aside>

    <!-- ⚠️ THE RESTING STATE IS RENDERED, NOT OMITTED. A panel that appears only once something
         is chosen makes the grid jump sideways on the first click — and until that click, nothing
         suggests that choosing a file shows anything at all. -->
    <aside v-else :class="cls('empty')" :aria-label="words.empty">
        <p :class="cls('emptyTitle')">{{ words.empty }}</p>
        <p :class="cls('emptyHint')">{{ words.emptyHint }}</p>
    </aside>
</template>
