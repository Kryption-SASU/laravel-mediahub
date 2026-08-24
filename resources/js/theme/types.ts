/*
 * THE STYLE IS DATA, AND THIS FILE SAYS WHAT SHAPE THAT DATA HAS.
 *
 * ⚠️ THE MARKUP IS THE CONTRACT. These components are not meant to be published and edited: a
 * view that can be forked is a view that is forked, and from that day the package cannot move
 * without breaking the copy. Everything about the appearance is therefore settable from the
 * outside, without recompiling and without touching a line of this package — and whoever needs a
 * different STRUCTURE does not patch these components, they write their own on the composables,
 * which carry all of the logic and none of the markup.
 */

/**
 * ONE PLACE IN ONE COMPONENT, IN TWO HALVES — and the split is the whole design.
 *
 * ⚠️ `layout` BELONGS TO THE MARKUP AND IS NOT OVERRIDABLE; `class` is the skin, and a host
 * theme replaces it entirely. Merging both into a single string would make "the host wins" a
 * sentence rather than a rule: with utility classes, `p-4` against `p-2` is settled by the order
 * of the stylesheet, not by anyone's intent, and settling it properly needs a conflict-aware
 * merge — a dependency, imposed on every host, to resolve a conflict that need not exist.
 *
 * ⚠️ AND IT KEEPS MINOR VERSIONS SAFE. Adding a structural class to a component is an ordinary
 * change; if the host had to restate `flex` in order to change a colour, that ordinary change
 * would break every theme in the field.
 */
export interface MhSlotStyle {
    /** Structure. Ours, and the same in every theme. */
    layout?: string
    /** Skin. Replaced wholesale by a host theme. */
    class?: string
}

/** Every named place a single component exposes. */
export type MhComponentStyle = Record<string, MhSlotStyle>

/** The complete table: component name to its places. */
export type MhTheme = Record<string, MhComponentStyle>

/**
 * WHAT A HOST IS ALLOWED TO SAY.
 *
 * ⚠️ `layout` IS ABSENT FROM THIS TYPE ON PURPOSE, so that the compiler refuses it rather than
 * the runtime ignoring it. A host writing `layout` and watching nothing happen would reasonably
 * conclude the theming is broken.
 */
export type MhComponentOverride = Record<string, { class?: string }>

export type MhThemeOverride = Record<string, MhComponentOverride>

/**
 * The classes for one place, ready for the `class` attribute.
 *
 * ⚠️ IT RETURNS A STRING, NEVER `undefined`. A component spreading `undefined` into `class`
 * renders `class="undefined"` in some Vue versions and nothing in others; neither is a thing
 * anybody wants to debug from a screenshot.
 */
export type MhClasses = (slot: string) => string
