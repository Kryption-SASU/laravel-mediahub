<script setup lang="ts">
import { computed } from 'vue'
import type { UploadItem } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * WHAT IS GOING UP, AND WHAT WENT WRONG.
 *
 * ⚠️ EACH FILE HAS ITS OWN FATE. One request per file means one can be refused — too large, a
 * type nobody allows — while the rest land; a single bar for the batch would report the whole
 * thing as failed, and somebody would upload nineteen files again to recover one.
 *
 * ⚠️ AND PROGRESS IS ANNOUNCED SPARINGLY. A live region on a percentage that changes forty times
 * a second turns a screen reader into a metronome. The count of what is finished is what gets
 * announced; the bars themselves are for the eye.
 */
const props = withDefaults(
    defineProps<{
        items: readonly UploadItem[]
        title?: string
        retryLabel?: string
        abortLabel?: string
        clearLabel?: string
        ui?: MhComponentOverride
    }>(),
    {
        title: undefined,
        retryLabel: undefined,
        abortLabel: undefined,
        clearLabel: undefined,
        ui: undefined,
    },
)

defineEmits<{ retry: [id: string]; abort: [id: string]; clear: [] }>()

const cls = useMediaTheme('uploadQueue', () => props.ui)
const t = useMediaText()

/*
 * ⚠️ A LABEL PROP IS AN EXCEPTION, NOT THE ROUTE. Its default is the translation, so the
 * ordinary case needs no prop at all and a host changes wording by translating rather than
 * by passing forty strings through every screen. The prop stays for the one-off.
 */
const words = computed(() => ({
    title: props.title ?? t('queue.title'),
    retry: props.retryLabel ?? t('queue.retry'),
    abort: props.abortLabel ?? t('queue.abort'),
    clear: props.clearLabel ?? t('queue.clear'),
}))

const finished = computed(
    () => props.items.filter((item) => item.status === 'done' || item.status === 'failed').length,
)

const anyFinished = computed(() => finished.value > 0)

/** ⚠️ A PERCENTAGE, ROUNDED — a bar reading "43.7719%" to a screen reader is worse than useless. */
function percent(item: UploadItem): number {
    return Math.round(item.progress * 100)
}

function running(item: UploadItem): boolean {
    return item.status === 'uploading' || item.status === 'pending'
}
</script>

<template>
    <section v-if="items.length > 0" :class="cls('root')" :aria-label="words.title">
        <header :class="cls('header')">
            <p :class="cls('title')">{{ words.title }}</p>

            <!-- ⚠️ THE COUNT IS WHAT IS ANNOUNCED, once per file rather than once per event. -->
            <p :class="cls('summary')" role="status">{{ finished }} / {{ items.length }}</p>
        </header>

        <ul :class="cls('list')">
            <li v-for="item in items" :key="item.id" :class="cls('item')">
                <span :class="cls('name')">{{ item.file.name }}</span>

                <!--
                    ⚠️ A NATIVE `<progress>`, not a styled div. It carries its own role, its own
                    value semantics and the indeterminate state for free — and an upload whose
                    total the browser cannot compute is genuinely indeterminate rather than at
                    zero, which a bar drawn by hand almost always gets wrong.
                -->
                <progress
                    v-if="running(item)"
                    :class="cls('progress')"
                    :value="item.progress > 0 ? percent(item) : undefined"
                    max="100"
                    :aria-label="item.file.name"
                />

                <!-- ⚠️ `failed` IS A STATE, NOT A WORD FOR SOMEBODY TO READ. -->
                <span v-else :class="cls('status')">{{ t('queue.status.' + item.status) }}</span>

                <!-- ⚠️ THE REASON, NOT A GENERIC FAILURE. "too_large" and "mime_not_allowed" call
                     for different answers, and only the person can give either. -->
                <span v-if="item.message" :class="cls('error')" role="alert">
                    <slot name="error" :item="item" :reason="item.reason">{{ item.message }}</slot>
                </span>

                <button
                    v-if="running(item)"
                    type="button"
                    :class="cls('abort')"
                    :aria-label="`${words.abort}: ${item.file.name}`"
                    @click="$emit('abort', item.id)"
                >
                    {{ words.abort }}
                </button>

                <button
                    v-if="item.status === 'failed' || item.status === 'aborted'"
                    type="button"
                    :class="cls('retry')"
                    :aria-label="`${words.retry}: ${item.file.name}`"
                    @click="$emit('retry', item.id)"
                >
                    {{ words.retry }}
                </button>
            </li>
        </ul>

        <button
            v-if="anyFinished"
            type="button"
            :class="cls('clear')"
            @click="$emit('clear')"
        >
            {{ words.clear }}
        </button>
    </section>
</template>
