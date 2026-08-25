<script setup lang="ts">
import { computed, ref } from 'vue'
import type { HealthReport, MediaHubClient } from '../client'
import { MediaHubError } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import MhErrorState from './MhErrorState.vue'
import { ALERT_GLYPH, CHECK_GLYPH, CLOSE_GLYPH, GLYPH_BOX } from './glyphs'
import { useNativeDialog } from './useNativeDialog'

/**
 * WHAT THIS CONFIGURATION PROMISES, AGAINST WHAT THE MACHINE WILL DO.
 *
 * ⚠️ A CEILING THE RUNTIME REFUSES IS WORSE THAN A LOW ONE. A library set to accept two hundred
 * megabytes on a PHP that stops at eight refuses everything in between before a single line of
 * the package runs, with an empty response and no reason. Whoever wrote the two hundred reads
 * their own configuration, sees two hundred, and reports a broken uploader — the one bug report
 * nobody can act on. This is where they find out instead.
 *
 * ⚠️ THE SENTENCES COME FROM THE SERVER, AND NONE OF THEM IS BUILT HERE. They name directives,
 * measured values and a value to set; a screen that turned a key into a sentence would be
 * inventing the numbers. What this component decides is where they go and what colour they are.
 *
 * ⚠️ AND IT IS ASKED FOR ON A CLICK, NEVER ON A MOUNT. Reading `php.ini` and probing extensions
 * on every visit to a media library is work nobody asked for, on a screen that opens all day.
 */
const props = withDefaults(
    defineProps<{
        /** Whether the report is on screen. */
        open: boolean
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    { client: undefined, ui: undefined },
)

const emit = defineEmits<{ close: [] }>()

const cls = useMediaTheme('healthReport', () => props.ui)
const t = useMediaText()
const api = resolveMediaHub(props.client)

const element = ref<HTMLDialogElement | null>(null)
const report = ref<HealthReport | null>(null)
const loading = ref(false)
const error = ref<MediaHubError | null>(null)

useNativeDialog(element, () => props.open)

/**
 * ⚠️ THE FINDINGS THAT ARE FINE COME LAST, and they are kept. A report that only listed problems
 * would be indistinguishable from a report that failed to run — and the line saying a limit was
 * checked and is sound is the one that stops somebody changing it for no reason.
 */
const ordered = computed(() => {
    const rank: Record<string, number> = { error: 0, warning: 1, ok: 2 }

    return [...(report.value?.checks ?? [])].sort(
        (one, other) => (rank[one.level] ?? 3) - (rank[other.level] ?? 3),
    )
})

const wrong = computed(() => ordered.value.filter((one) => one.level !== 'ok').length)

/**
 * THE MARK EACH LEVEL WEARS.
 *
 * ⚠️ A DISC AND A DRAWING, READ BEFORE THE WORD BESIDE IT. A page of findings is scanned before
 * it is read: somebody wants to know whether there is anything to do here, and a column of
 * identical grey labels makes them read all of it to find out.
 *
 * ⚠️ AND THE WORD STAYS. Colour alone is unreadable to a tenth of the people looking at it and to
 * everybody who prints the page, and a tick and a cross at sixteen pixels are not as different as
 * they look when one is red and the other green to nobody.
 *
 * ⚠️ THE SLOT IS NAMED HERE RATHER THAN BUILT FROM THE LEVEL. `cls('dot' + level)` would put a
 * string the theme has never heard of one typo away, and the failure would be an unstyled disc
 * rather than an error.
 */
const MARKS: Record<string, { slot: string; glyph: readonly string[] }> = {
    ok: { slot: 'dotOk', glyph: CHECK_GLYPH },
    warning: { slot: 'dotWarning', glyph: ALERT_GLYPH },
    error: { slot: 'dotError', glyph: CLOSE_GLYPH },
}

function mark(level: string): { slot: string; glyph: readonly string[] } {
    return MARKS[level] ?? MARKS['ok']!
}

async function run(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        report.value = await api.diagnostics()
    } catch (failure) {
        /*
         * ⚠️ A 404 HERE MEANS THE HOST HAS NOT TURNED THE REPORT ON, which is a different thing
         * from a machine with nothing to report — and showing an empty clean report for it would
         * certify a machine nobody looked at.
         */
        error.value =
            failure instanceof MediaHubError
                ? failure
                : new MediaHubError(0, null, 'The report could not be produced.')
    } finally {
        loading.value = false
    }
}

defineExpose({ run })
</script>

<template>
    <dialog
        ref="element"
        :class="cls('root')"
        :aria-label="t('health.title')"
        @cancel.prevent="emit('close')"
        @close="emit('close')"
    >
        <div :class="cls('body')">
            <div :class="cls('header')">
                <p :class="cls('title')">{{ t('health.title') }}</p>

                <button
                    type="button"
                    :class="cls('close')"
                    :aria-label="t('health.close')"
                    @click="emit('close')"
                >
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
                        <path v-for="(drawing, step) in CLOSE_GLYPH" :key="step" :d="drawing" />
                    </svg>
                </button>
            </div>

            <p v-if="loading" :class="cls('summary')">{{ t('health.running') }}</p>

            <MhErrorState v-else-if="error" :error="error" />

            <template v-else-if="report">
                <!-- ⚠️ THE HEADLINE IS A COUNT, NOT A COLOUR. "Two things to look at" is what
                     somebody acts on; a green tick they have to interpret is not. -->
                <p :class="cls('summary')">
                    {{ wrong === 0 ? t('health.allWell') : t('health.somethingToDo', {}, wrong) }}
                </p>

                <ul :class="cls('list')">
                    <li v-for="check in ordered" :key="check.id" :class="cls('entry')">
                        <p :class="cls('badge')">
                            <span :class="cls(mark(check.level).slot)">
                                <svg
                                    :class="cls('markIcon')"
                                    :viewBox="GLYPH_BOX"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path
                                        v-for="(drawing, step) in mark(check.level).glyph"
                                        :key="step"
                                        :d="drawing"
                                    />
                                </svg>
                            </span>

                            <span :class="cls(check.level)">
                                {{ t('health.level.' + check.level) }}
                            </span>
                        </p>

                        <p :class="cls('checkTitle')">{{ check.title }}</p>
                        <p :class="cls('detail')">{{ check.detail }}</p>

                        <!-- ⚠️ THE HALF THAT ASKS SOMEBODY TO ACT, and it is set apart because it
                             is the only line on the screen that is an instruction. -->
                        <p v-if="check.recommendation" :class="cls('advice')">
                            {{ check.recommendation }}
                        </p>
                    </li>
                </ul>
            </template>
        </div>
    </dialog>
</template>
