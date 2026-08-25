<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Media, MediaHubClient } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import { resolveMediaHub } from '../vue/context'
import { startDownload } from './download'
import { CLOSE_GLYPH, DOWNLOAD_GLYPH, GLYPH_BOX, TYPE_GLYPHS } from './glyphs'
import { useNativeDialog } from './useNativeDialog'

/**
 * ONE FILE, AS LARGE AS THE SCREEN ALLOWS.
 *
 * ⚠️ A THUMBNAIL IS NOT A LOOK AT A FILE. A hundred and sixty pixels tells somebody which
 * photograph it is; it does not tell them whether it is sharp, whether the crop works, or what
 * the small print says. Every library people trust has this, and its absence is what sends them
 * back to downloading a file to find out what is in it.
 *
 * ⚠️ THE FILE IS WHAT OPENS IT, as with the details window: no `open` prop beside `media`,
 * because two ways of saying the same thing drift, and the pair that drifts here is a viewer
 * showing nothing at full screen.
 *
 * ⚠️ AND NO KIND OF FILE GIVES A BLANK SCREEN. A video plays, a sound plays, a PDF is rendered by
 * the browser that already knows how; anything else says so plainly and offers the one thing that
 * does work on it. A viewer that opens black on a spreadsheet reads as broken, not as unable.
 *
 * ⚠️ IT SHOWS THE ONE FILE IT WAS ASKED ABOUT, AND OFFERS NO WAY TO THE NEXT. Stepping sounds
 * free and is not: it only ever covers the page somebody happens to be on, so the arrows stop
 * at a boundary that means nothing to them, and a viewer that pages behind their back changes
 * the screen underneath without being asked. Closing and clicking the next file is one gesture,
 * and it is the one that does what it looks like.
 */
const props = withDefaults(
    defineProps<{
        /** The file being looked at. `null` closes the viewer — it is the same statement. */
        media: Media | null
        client?: MediaHubClient
        ui?: MhComponentOverride
    }>(),
    { client: undefined, ui: undefined },
)

const emit = defineEmits<{ close: [] }>()

const cls = useMediaTheme('lightbox', () => props.ui)
const t = useMediaText()

const element = ref<HTMLDialogElement | null>(null)
const api = resolveMediaHub(props.client)

useNativeDialog(element, () => props.media !== null)

const kind = computed(() => {
    const media = props.media

    if (media === null) {
        return 'none'
    }

    if (media.type === 'image' || media.type === 'video' || media.type === 'audio') {
        return media.type
    }

    /* ⚠️ THE MIME TYPE DECIDES, NOT THE FAMILY. A PDF is a "document" like a spreadsheet is, and
     * only one of the two is something a browser can draw. */
    return media.mime_type === 'application/pdf' ? 'pdf' : 'other'
})

const glyph = computed(() => TYPE_GLYPHS[props.media?.type ?? 'other'])

/**
 * A DOCUMENT IS READ THROUGH THIS PACKAGE'S OWN ROUTE, NOT THROUGH THE ADDRESS IN THE RESOURCE.
 *
 * ⚠️ AND THAT IS A MEASUREMENT, NOT A PRECAUTION. Object storage signs its links with
 * `Content-Disposition: attachment` on every object — measured on a real container on
 * 25/08/2026 — and a frame pointed at one downloads the file instead of showing it. Nothing
 * warns: the viewer opens blank behind a save dialog, which reads as the preview being broken.
 *
 * ⚠️ AN IMAGE AND A VIDEO ARE UNAFFECTED, which is why this is not the address used for them.
 * `<img>` and `<video>` fetch their bytes and ignore that header entirely; only the frame, which
 * is a navigation, honours it.
 *
 * ⚠️ AND THE BYTES CANNOT BE FETCHED AND HANDED OVER AS A BLOB EITHER — the usual answer to
 * this. The same container answers with no `Access-Control-Allow-Origin` at all, so the request
 * never completes. The package's own route is same-origin and streams `inline`, which settles
 * both at once.
 */
const reading = computed(() => (props.media === null ? '' : api.url(props.media.id + '/file')))

/**
 * ⚠️ THE BACKDROP IS PART OF THE DIALOG ELEMENT, NOT A SEPARATE LAYER. A click on it arrives with
 * the dialog itself as its target, which is the only way to tell it apart from a click on what is
 * being shown — `contains()` would answer true for both, and closing the viewer on a click on the
 * photograph is how people lose their place.
 */
function onClick(event: MouseEvent): void {
    if (event.target === element.value) {
        emit('close')
    }
}

function save(): void {
    if (props.media) {
        startDownload(props.media.download_url, props.media.file_name)
    }
}
</script>

<template>
    <!-- ⚠️ `@cancel.prevent` THEN OUR OWN CLOSE: letting the browser close it natively would leave
         the file on the caller's side, and the next click on that same file would open nothing at
         all — a viewer that works once. -->
    <dialog
        ref="element"
        :class="cls('root')"
        :aria-label="t('viewer.label')"
        @cancel.prevent="emit('close')"
        @close="emit('close')"
        @click="onClick"
    >
        <div v-if="media" :class="cls('stage')">
            <button
                type="button"
                :class="cls('close')"
                :aria-label="t('viewer.close')"
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

            <div :class="cls('frame')">
                <!-- ⚠️ `object-contain`, NEVER A CROP. The tile already cropped it once to fit a
                     square; cropping the full view too would leave nowhere to see the whole
                     picture, which is the one thing this window is for. -->
                <img
                    v-if="kind === 'image'"
                    :class="cls('image')"
                    :src="media.url"
                    :alt="media.name"
                />

                <video v-else-if="kind === 'video'" :class="cls('image')" :src="media.url" controls />

                <audio v-else-if="kind === 'audio'" :class="cls('sound')" :src="media.url" controls />

                <iframe
                    v-else-if="kind === 'pdf'"
                    :class="cls('document')"
                    :src="reading"
                    :title="media.name"
                />

                <!-- ⚠️ AND THE REST SAYS SO, rather than showing an empty rectangle. The one act
                     that works on any file at all is offered right there. -->
                <div v-else :class="cls('unviewable')">
                    <svg
                        :class="cls('unviewableIcon')"
                        :viewBox="GLYPH_BOX"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path v-for="(drawing, step) in glyph" :key="step" :d="drawing" />
                    </svg>

                    <p :class="cls('unviewableText')">{{ t('viewer.unviewable') }}</p>

                    <button type="button" :class="cls('save')" @click="save">
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
                            <path
                                v-for="(drawing, step) in DOWNLOAD_GLYPH"
                                :key="step"
                                :d="drawing"
                            />
                        </svg>

                        {{ t('actions.download') }}
                    </button>
                </div>
            </div>

            <p :class="cls('caption')">{{ media.name }}</p>
        </div>
    </dialog>
</template>
