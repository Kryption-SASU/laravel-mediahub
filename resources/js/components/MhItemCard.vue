<script setup lang="ts">
import { computed } from 'vue'
import type { Media } from '../client'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'
import MhThumbnail from './MhThumbnail.vue'
import { CHECK_GLYPH, GLYPH_BOX, MENU_GLYPH } from './glyphs'

/**
 * ONE MEDIA, AS SOMETHING YOU CAN CHOOSE.
 *
 * ⚠️ IT IS AN OPTION, NOT A BUTTON. Inside a grid it carries `role="option"` and its selected
 * state, so assistive technology announces "3 of 24, selected" rather than reading twenty-four
 * unrelated buttons. That is not a detail on a picker: choosing a file is the only thing the
 * screen is for.
 *
 * ⚠️ AND IT IS NOT INDEPENDENTLY TABBABLE. Focus belongs to the grid — twenty-four items each
 * taking a tab stop turn a picker into something a keyboard user has to escape from rather than
 * use.
 *
 * ⚠️ IT DOES NOT RESTYLE THE THUMBNAIL EITHER. Passing its own classes down would silently
 * override whatever the host set on `thumbnail`, and the host would be left theming a component
 * that ignores them in the one place it is actually seen.
 */
const props = withDefaults(
    defineProps<{
        media: Media
        selected?: boolean
        /** Position in the grid, announced as "n of total". */
        index?: number
        total?: number
        /** Whether this is the item the grid's single tab stop currently sits on. */
        focused?: boolean
        /**
         * ⚠️ WHETHER THE SCREEN IS BUSY CHOOSING. In that state a tile does one thing and one
         * thing only, so the menu is not offered: a control that acts on a file while somebody is
         * halfway through picking a dozen is a second answer to a question they have not finished
         * asking.
         */
        picking?: boolean
        /**
         * ⚠️ WHETHER SOMETHING IS BEING DONE TO THIS FILE RIGHT NOW. Drawn on the tile rather than
         * over the screen: a veil in the middle of the window blocks a library somebody could
         * still be using, and says nothing about which file it is waiting on. Here, the answer to
         * "is it working?" and the answer to "on what?" are the same mark.
         */
        busy?: boolean
        /**
         * A figure to put beside the mark, when the act running can count itself.
         *
         * ⚠️ NULL IS THE ORDINARY CASE, and it means "draw the spinner and say nothing". Almost
         * every act here is over before a number could be read; the archive is the exception,
         * because the browser reports nothing about a download it has taken over and the server
         * is the only witness left to ask.
         */
        busyLabel?: string | null
        ui?: MhComponentOverride
    }>(),
    {
        selected: false,
        index: undefined,
        total: undefined,
        focused: false,
        picking: false,
        busy: false,
        busyLabel: null,
        ui: undefined,
    },
)

/**
 * ⚠️ ASKED FOR ON THE ITEM ITSELF, at the point the pointer is at. The actions used to be
 * reachable only by right-clicking once something was already ticked — which is a rule nobody
 * discovers, on a screen where the obvious move is to right-click the thing you mean.
 */
const emit = defineEmits<{ menu: [event: MouseEvent] }>()

const cls = useMediaTheme('itemCard', () => props.ui)
const t = useMediaText()

const rootClasses = computed(() =>
    [cls('root'), props.selected ? cls('selected') : ''].join(' ').trim(),
)
</script>

<template>
    <div
        :class="rootClasses"
        role="option"
        :aria-selected="selected"
        :aria-setsize="total"
        :aria-posinset="index"
        :tabindex="focused ? 0 : -1"
        :aria-busy="busy || undefined"
        @contextmenu.prevent.stop="picking || busy || emit('menu', $event)"
    >
        <!--
            ⚠️ IT COVERS THE TILE, WHICH IS ALSO HOW IT MAKES IT INERT. Everything underneath keeps
            working while one file is being copied — that is the point of not veiling the screen —
            but the file being copied must not be asked to do a second thing at the same time, and
            an overlay that swallows the pointer says so without a `disabled` on four controls.

            ⚠️ AND THE STATE IS ANNOUNCED, NOT ONLY DRAWN. `aria-busy` above is what a screen
            reader has to go on; a spinning ring is nothing at all to it.
        -->
        <span v-if="busy" :class="cls('busy')">
            <svg
                :class="cls('spinner')"
                :viewBox="GLYPH_BOX"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <circle cx="12" cy="12" r="9" stroke-opacity="0.3" />
                <path d="M21 12a9 9 0 0 0-9-9" />
            </svg>

            <!-- ⚠️ WRITTEN ONLY WHEN THERE IS SOMETHING TO WRITE. An act that cannot count
                 itself leaves this out rather than showing a zero, which reads as a download
                 that has stalled before it started. -->
            <span v-if="busyLabel" :class="cls('busyLabel')">{{ busyLabel }}</span>
        </span>

        <!-- ⚠️ SHOWN BECAUSE IT IS TICKED, not because a pointer is over it. This is the one mark
             on the tile that has to survive being looked at from across the room. -->
        <span v-if="selected" :class="cls('tick')" :aria-hidden="true">
            <svg
                :class="cls('tickIcon')"
                :viewBox="GLYPH_BOX"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path v-for="(drawing, step) in CHECK_GLYPH" :key="step" :d="drawing" />
            </svg>
        </span>

        <!--
            ⚠️ NOT IN THE TAB ORDER, AND THAT IS NOT AN OVERSIGHT. The grid is one tab stop with
            the arrows moving inside it; a button per item would put twenty-four more between a
            keyboard user and the rest of the page. The keyboard route to these actions is the
            context-menu key, which the browser fires as `contextmenu` on the focused item — the
            handler above — so nothing is lost by leaving this one to the pointer.

            ⚠️ AND IT IS SHOWN ON FOCUS AS WELL AS ON HOVER. A control that only exists under a
            pointer is invisible to anybody driving the page any other way, including somebody who
            reached the card with the arrows and wonders what can be done with it.
        -->
        <button
            v-if="!picking && !busy"
            type="button"
            tabindex="-1"
            :class="cls('menu')"
            :aria-label="t('menu.label')"
            @click.stop="emit('menu', $event)"
        >
            <svg
                :class="cls('menuIcon')"
                :viewBox="GLYPH_BOX"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path v-for="(drawing, step) in MENU_GLYPH" :key="step" :d="drawing" />
            </svg>
        </button>

        <!-- ⚠️ THE FRAME IS WHAT GIVES THE THUMBNAIL A HEIGHT. Asked for `100%` inside a column
             that has none of its own, the picture falls back to its own dimensions, and a grid
             of portrait wallpapers beside videos comes out as a staircase. -->
        <span :class="cls('preview')">
            <!-- ⚠️ DECORATIVE, BECAUSE THE NAME IS RIGHT THERE. Left describing itself, a screen
                 reader announces the same file name twice for every item in the grid. -->
            <MhThumbnail :media="media" :alt="null" size="100%" />
        </span>

        <span :class="cls('name')" :title="media.name">{{ media.name }}</span>

        <slot name="meta" :media="media" />
    </div>
</template>
