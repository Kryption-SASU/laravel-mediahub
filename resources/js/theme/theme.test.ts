import { describe, expect, it } from 'vitest'
import { defaultTheme } from './defaults'
import { classesOf, mergeTheme } from './merge'
import type { MhTheme } from './types'

const base: MhTheme = {
    thumbnail: {
        root: { layout: 'relative block', class: 'rounded-md bg-slate-100' },
        image: { layout: 'h-full w-full', class: '' },
    },
}

describe('a host theme taking over', () => {
    /** ⚠️ THE RULE, IN ONE TEST: what the host says replaces what we say. */
    it('replaces the skin outright', () => {
        const merged = mergeTheme(base, { thumbnail: { root: { class: 'rounded-none bg-brand' } } })

        expect(classesOf(merged, 'thumbnail', 'root')).toBe('relative block rounded-none bg-brand')
    })

    /**
     * ⚠️ AND IT CANNOT TAKE THE STRUCTURE WITH IT. `layout` is part of the markup contract: if a
     * host could drop it, adding one structural class in a minor version would break every theme
     * in the field, and the breakage would show as a misaligned screen rather than an error.
     */
    it('cannot take the structure with it', () => {
        const hostile = { thumbnail: { root: { class: 'bg-brand', layout: 'absolute' } } }

        const merged = mergeTheme(base, hostile as never)

        expect(classesOf(merged, 'thumbnail', 'root')).toBe('relative block bg-brand')
    })

    /**
     * ⚠️ AN EMPTY STRING REMOVES THE SKIN — it does not mean "nothing was said". Read as absent,
     * the default colours would come back and a host would have no way to strip them at all.
     */
    it('lets a host remove a skin entirely', () => {
        const merged = mergeTheme(base, { thumbnail: { root: { class: '' } } })

        expect(classesOf(merged, 'thumbnail', 'root')).toBe('relative block')
    })

    it('leaves untouched places alone', () => {
        const merged = mergeTheme(base, { thumbnail: { root: { class: 'x' } } })

        expect(classesOf(merged, 'thumbnail', 'image')).toBe('h-full w-full')
    })

    /** ⚠️ NEAREST WINS — otherwise a local adjustment is unpredictable, and unpredictable means copied. */
    it('gives the last word to the nearest override', () => {
        const merged = mergeTheme(
            base,
            { thumbnail: { root: { class: 'from-global' } } },
            { thumbnail: { root: { class: 'from-prop' } } },
        )

        expect(classesOf(merged, 'thumbnail', 'root')).toBe('relative block from-prop')
    })

    it('ignores an absent override rather than failing on it', () => {
        expect(classesOf(mergeTheme(base, undefined), 'thumbnail', 'root')).toBe(
            'relative block rounded-md bg-slate-100',
        )
    })

    /**
     * ⚠️ A COMPONENT WE DO NOT SHIP IS STILL THEMED. Someone writing their own component against
     * this table — which is the only consistent way we offer them — must be able to put it in the
     * same theme as ours.
     */
    it('accepts a component the default theme has never heard of', () => {
        const merged = mergeTheme(base, { theirOwnCard: { root: { class: 'p-4' } } })

        expect(classesOf(merged, 'theirOwnCard', 'root')).toBe('p-4')
    })

    /** ⚠️ NEVER `undefined` — a component spreading that renders `class="undefined"` on some builds. */
    it('answers with a string for a place nobody declared', () => {
        expect(classesOf(base, 'thumbnail', 'nope')).toBe('')
        expect(classesOf(base, 'nope', 'root')).toBe('')
    })

    /**
     * ⚠️ THE MERGE DOES NOT WRITE INTO THE DEFAULT THEME. It is a module-level constant shared by
     * every instance on the page: mutating it would let one host's brand colour leak into another
     * component tree, and only when both happen to be mounted.
     */
    it('leaves the theme it was given untouched', () => {
        mergeTheme(base, { thumbnail: { root: { class: 'mutated' } } })

        expect(base.thumbnail?.root?.class).toBe('rounded-md bg-slate-100')
    })
})

describe('what the default skin has to carry itself', () => {
    /**
     * ⚠️ A MODAL `<dialog>` IS CENTRED BY THE BROWSER, AND TAILWIND TAKES THAT AWAY. The centring
     * is `margin: auto` against `inset: 0` in the user-agent stylesheet; the preflight resets the
     * margin of every element to zero, and the prompt lands in the top-left corner of the window.
     * Nothing warns — the backdrop still appears, the focus trap still works, the box is simply
     * in the wrong place. Seen on a real host on 25/08/2026.
     *
     * ⚠️ THE DIALOGS ARE FOUND RATHER THAN LISTED. `backdrop:` only applies to a dialog, so a
     * fourth one added later is covered the day it is written — a hand-written list of three
     * names would not be, and nobody would notice until a screenshot.
     */
    it('centres every dialog itself', () => {
        const dialogs = Object.entries(defaultTheme).flatMap(([component, slots]) =>
            Object.entries(slots)
                .filter(([, style]) => (style.layout ?? '').includes('backdrop:'))
                .map(([slot, style]) => [component + '.' + slot, style.layout ?? ''] as const),
        )

        expect(dialogs.length).toBeGreaterThan(0)

        expect(
            dialogs.filter(([, layout]) => !/\bm-auto\b/.test(layout)).map(([where]) => where),
        ).toEqual([])
    })

    /**
     * ⚠️ AND THE CENTRING IS STRUCTURE, NOT SKIN. A host replacing the surface of a dialog — its
     * colour, its shadow, its radius — must not be able to lose the one class that puts it in
     * the middle of the window.
     */
    it('keeps that centring out of a host theme\'s reach', () => {
        const merged = mergeTheme(defaultTheme, {
            confirmDialog: { root: { class: 'bg-white' } },
        })

        expect(classesOf(merged, 'confirmDialog', 'root')).toContain('m-auto')
    })
})

describe('the three listboxes that share a screen', () => {
    /**
     * ⚠️ THE FOLDERS, THE FILES AND THE SKELETON STANDING IN FOR BOTH ARE ONE GRID TO THE EYE.
     * They are three listboxes on purpose — a folder row and a file grid are not the same
     * choice, and the keyboard has to be able to tell them apart — but they sit one above the
     * other, so their columns have to line up. Half a tile of drift reads as two grids that
     * failed to meet, and a skeleton on a different ladder makes the screen jump the moment the
     * answer arrives.
     *
     * ⚠️ THIS IS WHAT KEEPS A DENSITY CHANGE HONEST. Tightening the grid is a one-word edit
     * repeated three times, which is exactly the kind of edit that gets made twice.
     */
    it('are drawn on the same ladder of columns', () => {
        const ladders = [
            classesOf(defaultTheme, 'itemGrid', 'root'),
            classesOf(defaultTheme, 'folderList', 'list'),
            classesOf(defaultTheme, 'skeleton', 'root'),
        ].map((classes) =>
            classes
                .split(/\s+/)
                .filter((one) => /(^|:)grid-cols-/.test(one))
                .join(' '),
        )

        expect(ladders[0]).not.toBe('')
        expect(ladders[1]).toBe(ladders[0])
        expect(ladders[2]).toBe(ladders[0])
    })
})
