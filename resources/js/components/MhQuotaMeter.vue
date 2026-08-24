<script setup lang="ts">
import { computed } from 'vue'
import type { Quota } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * HOW MUCH ROOM IS LEFT.
 *
 * ⚠️ AN UNLIMITED QUOTA HAS NO GAUGE AT ALL, and zero would be worse than nothing: a bar at 0 %
 * reads as "empty" and at 100 % as "full", while the truth is that the question does not apply.
 * Where there is no limit, this component says so in words and draws nothing.
 */
const props = withDefaults(
    defineProps<{
        quota: Quota | null
        label?: string
        unlimitedLabel?: string
        ui?: MhComponentOverride
    }>(),
    { label: undefined, unlimitedLabel: undefined, ui: undefined },
)

const cls = useMediaTheme('quotaMeter', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    label: props.label ?? t('quota.label'),
    unlimited: props.unlimitedLabel ?? t('quota.unlimited'),
}))

const unlimited = computed(
    () => props.quota === null || props.quota.unlimited || props.quota.limit === null,
)

/**
 * ⚠️ CAPPED AT ONE. A gauge past the end of its track reads as a rendering fault rather than as a
 * warning — and going over the limit is exactly when it needs to be read as a warning.
 *
 * ⚠️ AND A LIMIT OF ZERO YIELDS NO GAUGE, not a division. It is a legitimate configuration
 * ("this tenant may store nothing"), and the arithmetic for it is `0 / 0`.
 */
const ratio = computed<number | null>(() => {
    const quota = props.quota

    if (!quota || quota.limit === null || quota.limit <= 0) {
        return null
    }

    return Math.min(1, Math.max(0, quota.used / quota.limit))
})

const percent = computed(() => (ratio.value === null ? null : Math.round(ratio.value * 100)))

/**
 * ⚠️ SIZES ARE SPELLED OUT, NOT LEFT AS BYTES. "1073741824" is not a quantity anybody reads; it
 * is a number they have to convert, every time they look at it.
 */
function readable(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB']

    let value = Math.max(0, bytes)
    let unit = 0

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024
        unit += 1
    }

    /*
     * ⚠️ A TRAILING ZERO IS NOISE. "1.0 GB" is a decimal nobody asked for, while "1.5 GB" is
     * information: one decimal below ten, none above it, and never a bare ".0".
     */
    const rounded =
        value >= 10 || unit === 0
            ? String(Math.round(value))
            : value.toFixed(1).replace(/\.0$/, '')

    return rounded + ' ' + units[unit]
}

const summary = computed(() => {
    const quota = props.quota

    if (!quota) {
        return ''
    }

    if (unlimited.value) {
        return readable(quota.used) + ' — ' + words.value.unlimited
    }

    return readable(quota.used) + ' / ' + readable(quota.limit ?? 0)
})
</script>

<template>
    <div v-if="quota" :class="cls('root')">
        <p :class="cls('label')">{{ words.label }}</p>

        <!-- ⚠️ A NATIVE `<meter>`: it carries the role, the range and the value on its own, and a
             browser that renders it its own way is a browser doing something better than we
             would. Drawn where there is a limit, and only there. -->
        <meter
            v-if="percent !== null"
            :class="cls('meter')"
            :value="percent"
            min="0"
            max="100"
            :aria-label="words.label"
        >
            {{ percent }}%
        </meter>

        <p :class="cls('summary')">{{ summary }}</p>
    </div>
</template>
