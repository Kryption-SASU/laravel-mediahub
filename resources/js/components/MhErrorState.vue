<script setup lang="ts">
import { computed } from 'vue'
import type { MediaHubError } from '../client'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * SOMETHING WAS REFUSED, AND THE SCREEN SAYS WHICH.
 *
 * ⚠️ THE SENTENCE COMES FROM THE SERVER, THE BRANCHING FROM THE KEY. The server guarantees that
 * `reason` never changes and is never translated, and guarantees the opposite of `message`. A
 * component matching on the sentence would break the day somebody improves the wording, or the
 * day a user switches language — and would break silently, showing a generic failure instead of
 * the real one.
 *
 * ⚠️ THE KEY IS HANDED TO THE SLOT rather than interpreted here. Only the host knows whether
 * `quota_exceeded` should offer to buy more room or to go and delete something.
 */
const props = withDefaults(
    defineProps<{
        error: MediaHubError | null
        title?: string
        /** Absent means no button: not everything is worth trying again. */
        retryLabel?: string
        ui?: MhComponentOverride
    }>(),
    { title: undefined, retryLabel: undefined, ui: undefined },
)

defineEmits<{ retry: [] }>()

const cls = useMediaTheme('errorState', () => props.ui)

const reason = computed<string | null>(() => props.error?.reason ?? null)

/*
 * ⚠️ `role="alert"` RATHER THAN A PLAIN PARAGRAPH. This replaces content somebody asked for; if
 * it is not announced, a screen reader user is left waiting on a list that will never come.
 */
</script>

<template>
    <div v-if="error" :class="cls('root')" role="alert">
        <p v-if="title" :class="cls('title')">{{ title }}</p>

        <p :class="cls('message')">
            <slot :error="error" :reason="reason">{{ error.message }}</slot>
        </p>

        <button v-if="retryLabel" type="button" :class="cls('retry')" @click="$emit('retry')">
            {{ retryLabel }}
        </button>
    </div>
</template>
