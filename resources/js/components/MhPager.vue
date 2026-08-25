<script setup lang="ts">
import { computed } from 'vue'
import { useMediaText } from '../i18n/context'
import { useMediaTheme } from '../theme/context'
import type { MhComponentOverride } from '../theme/types'

/**
 * WHERE YOU ARE IN A LISTING, AND HOW TO GET ELSEWHERE IN IT.
 *
 * ⚠️ WITHOUT THIS THE LISTING WAS SILENTLY TRUNCATED. The server paginated from the first day and
 * no screen ever asked for a second page: a folder of three hundred files showed forty-eight and
 * said nothing about the other two hundred and fifty-two. That is worse than no pagination — an
 * absent feature is noticed, a cut listing is not.
 *
 * ⚠️ AND IT SAYS HOW MUCH THERE IS, not only where you are. "Page 2 of 7" tells somebody they can
 * keep clicking; "312 items" tells them to search instead, which in a media library is usually
 * the better move.
 *
 * ⚠️ NUMBERED PAGES RATHER THAN "LOAD MORE" OR AN ENDLESS SCROLL. Both of those lose the position:
 * a file seen a minute ago cannot be gone back to, and a keyboard has nothing to aim at. A page
 * number is a place, and it survives a reload.
 */
const props = withDefaults(
    defineProps<{
        page: number
        pages: number
        total: number
        /** How many numbers to show around the current one, on top of the first and the last. */
        around?: number
        ui?: MhComponentOverride
    }>(),
    { around: 2, ui: undefined },
)

const emit = defineEmits<{ go: [page: number] }>()

const cls = useMediaTheme('pager', () => props.ui)
const t = useMediaText()

/**
 * THE NUMBERS WORTH DRAWING, AND A GAP WHERE THE REST WOULD BE.
 *
 * ⚠️ A THOUSAND PAGES CANNOT ALL BE BUTTONS. The first and the last are always there — they are
 * the two places people actually aim for — with a window around the current one and a gap marked
 * between them. Rendering every number instead turns a control into a wall.
 */
const numbers = computed<Array<number | null>>(() => {
    const shown = new Set<number>([1, props.pages])

    for (let step = -props.around; step <= props.around; step++) {
        const page = props.page + step

        if (page >= 1 && page <= props.pages) {
            shown.add(page)
        }
    }

    const ordered = [...shown].sort((one, other) => one - other)
    const drawn: Array<number | null> = []

    for (const page of ordered) {
        const last = drawn[drawn.length - 1]

        /* ⚠️ ONE GAP, NOT ONE PER MISSING PAGE. */
        if (typeof last === 'number' && page - last > 1) {
            drawn.push(null)
        }

        drawn.push(page)
    }

    return drawn
})

const summary = computed(() => t('pages.summary', {}, props.total))

const where = computed(() =>
    t('pages.where', { page: props.page, pages: props.pages }),
)

function go(page: number): void {
    /* ⚠️ NOTHING IS ASKED FOR TWICE. Clicking the page already shown would spend a round trip to
     * redraw what is on screen, and blank the grid while it waited. */
    if (page !== props.page && page >= 1 && page <= props.pages) {
        emit('go', page)
    }
}
</script>

<template>
    <!-- ⚠️ HIDDEN WHEN THERE IS ONE PAGE, BUT THE COUNT IS NOT. How much a folder holds is worth
         knowing whether or not it spills over. -->
    <nav :class="cls('root')" :aria-label="t('pages.label')">
        <p :class="cls('summary')">{{ summary }}</p>

        <div v-if="pages > 1" :class="cls('pages')">
            <button
                type="button"
                :class="cls('step')"
                :disabled="page <= 1"
                :aria-label="t('pages.previous')"
                @click="go(page - 1)"
            >
                ‹
            </button>

            <template v-for="(number, position) in numbers" :key="position">
                <!-- ⚠️ THE GAP IS NOT A BUTTON, and says nothing to a screen reader: it is the
                     absence of pages, not a page anybody can go to. -->
                <span v-if="number === null" :class="cls('gap')" aria-hidden="true">…</span>

                <button
                    v-else
                    type="button"
                    :class="number === page ? cls('current') : cls('page')"
                    :aria-current="number === page ? 'page' : undefined"
                    @click="go(number)"
                >
                    {{ number }}
                </button>
            </template>

            <button
                type="button"
                :class="cls('step')"
                :disabled="page >= pages"
                :aria-label="t('pages.next')"
                @click="go(page + 1)"
            >
                ›
            </button>
        </div>

        <p v-if="pages > 1" :class="cls('where')">{{ where }}</p>
    </nav>
</template>
