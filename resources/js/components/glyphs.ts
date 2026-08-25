import type { MediaType } from '../client'

/**
 * THE DRAWINGS, AS DATA.
 *
 * ⚠️ A FILE THAT CANNOT BE PICTURED STILL HAS TO SAY WHAT IT IS. The library used to print the
 * first four letters of the extension in the middle of the tile, which turned a video with no
 * extension into a box reading "VIDE" and a spreadsheet into "XLSX" — legible, and telling
 * nobody anything at a glance. A glyph is read before it is decoded, which is the whole job of a
 * tile you are scanning twenty of.
 *
 * ⚠️ THEY ARE PATHS RATHER THAN AN ICON DEPENDENCY. Six drawings do not justify a package, and a
 * package would arrive with its own sizing, its own colours and its own opinion about which
 * framework it lives in — three things this one exists to avoid imposing.
 *
 * ⚠️ AND EVERY ONE OF THEM IS REPLACEABLE FROM THE OUTSIDE. Each component drawing one exposes
 * an `icon` slot, so a host with an icon set of its own never has to fork a template to use it.
 *
 * ⚠️ STROKED, NOT FILLED, AND ON A 24 GRID. `currentColor` then makes the colour a matter of the
 * theme table like everything else, and one `stroke-width` keeps six drawings looking like one
 * family rather than six borrowed ones.
 */
export const GLYPH_BOX = '0 0 24 24'

/** ⚠️ EVERY PATH STARTS WITH AN ABSOLUTE `M`. A relative `m-4` opening would read as a Tailwind
 * class to the guard that forbids hardcoded classes, and the failure would be a puzzle. */
export const FOLDER_GLYPH: readonly string[] = [
    'M3 7.5A1.5 1.5 0 0 1 4.5 6h4l2 2.5h9A1.5 1.5 0 0 1 21 10v8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18Z',
]

export const FOLDER_ADD_GLYPH: readonly string[] = [
    ...FOLDER_GLYPH,
    'M12 11.5v6',
    'M9 14.5h6',
]

/**
 * ⚠️ THREE DOTS DRAWN AS ZERO-LENGTH STROKES. A round line cap turns `M12 6h.01` into a disc, so
 * the marks belong to the same family as every other glyph here — one `stroke-width`, one colour,
 * `currentColor` throughout — rather than being three filled circles that ignore all of it.
 */
export const MENU_GLYPH: readonly string[] = ['M12 6h.01', 'M12 12h.01', 'M12 18h.01']

export const TRASH_GLYPH: readonly string[] = [
    'M4 7h16',
    'M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7',
    'M6 7l1 12.5A1.5 1.5 0 0 0 8.5 21h7a1.5 1.5 0 0 0 1.5-1.5L18 7',
    'M10 11v6',
    'M14 11v6',
]

export const CHECK_GLYPH: readonly string[] = ['M5 12.5l4.5 4.5L19 7.5']

/** ⚠️ AN ARROW THAT TURNS BACK, WHICH IS WHAT UNDO LOOKS LIKE EVERYWHERE. An arrow pointing out
 * of a bin would be the literal act, and unreadable at sixteen pixels. */
export const RESTORE_GLYPH: readonly string[] = [
    'M4.5 12a7.5 7.5 0 1 0 2.2-5.3',
    'M4 5.5V10h4.5',
]

export const COPY_GLYPH: readonly string[] = [
    'M9 9h10v10a1.5 1.5 0 0 1-1.5 1.5H9Z',
    'M15 9V4.5A1.5 1.5 0 0 0 13.5 3h-8A1.5 1.5 0 0 0 4 4.5v10A1.5 1.5 0 0 0 5.5 16H9',
]

export const CLOSE_GLYPH: readonly string[] = ['M6 6l12 12', 'M18 6l-12 12']

/** ⚠️ AN EYE, NOT A MAGNIFIER. A magnifier says "search" on every other screen on the machine,
 * and this entry opens something rather than looking for it. */
export const EYE_GLYPH: readonly string[] = [
    'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12',
    'M14.5 12a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0',
]

/** ⚠️ TWO LINKS OF A CHAIN, WHICH IS WHAT AN ADDRESS IS EVERYWHERE ELSE. The duplicate glyph is
 * two sheets of paper, and the two entries sit next to each other on the menu — telling them
 * apart at a glance is the whole reason either has a drawing. */
export const LINK_GLYPH: readonly string[] = [
    'M10 13.5a3.5 3.5 0 0 0 5 0l3-3a3.5 3.5 0 0 0-5-5l-1.5 1.5',
    'M14 10.5a3.5 3.5 0 0 0-5 0l-3 3a3.5 3.5 0 0 0 5 5l1.5-1.5',
]

export const PENCIL_GLYPH: readonly string[] = [
    'M4 20h4l10-10a2.5 2.5 0 0 0-3.5-3.5L4.5 16.5Z',
    'M13.5 7l3.5 3.5',
]

/** ⚠️ THE SAME TWO SHEETS AS `COPY_GLYPH`, AND ON PURPOSE. Copying an address and duplicating a
 * file are not the same act, but "there are two of it now" is the same idea, and a second
 * drawing for it would be a distinction nobody asked for. */
export const DUPLICATE_GLYPH: readonly string[] = COPY_GLYPH

/** ⚠️ THE ARROW POINTS DOWN, WHERE THE UPLOAD'S POINTS UP, and both stand over the same tray.
 * The pair reads as one idea in two directions rather than as two unrelated drawings. */
export const DOWNLOAD_GLYPH: readonly string[] = [
    'M12 4v12',
    'M8 12l4 4 4-4',
    'M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15',
]

export const UPLOAD_GLYPH: readonly string[] = [
    'M12 16V4',
    'M8 8l4-4 4 4',
    'M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15',
]

const IMAGE_GLYPH: readonly string[] = [
    'M4 5h16v14H4Z',
    'M4 16l4.5-4.5 3.5 3.5 3-3L20 16',
    'M10 9.5a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0',
]

const VIDEO_GLYPH: readonly string[] = ['M4 6h11v12H4Z', 'M15 10l5-3v10l-5-3Z']

const AUDIO_GLYPH: readonly string[] = [
    'M9 17V6l10-2v11',
    'M9 17a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0',
    'M19 15a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0',
]

const DOCUMENT_GLYPH: readonly string[] = ['M6 3h7l5 5v13H6Z', 'M13 3v5h5']

const EXTERNAL_GLYPH: readonly string[] = ['M14 4h6v6', 'M20 4l-8 8', 'M18 14v6H4V6h6']

const OTHER_GLYPH: readonly string[] = ['M6 3h12v18H6Z']

/**
 * ⚠️ EVERY KIND THE SERVER CAN SEND HAS AN ENTRY, and the type is what says so. A lookup falling
 * through to `undefined` renders an empty frame — which reads as a file that failed to load
 * rather than one nobody drew an icon for.
 */
export const TYPE_GLYPHS: Record<MediaType, readonly string[]> = {
    image: IMAGE_GLYPH,
    video: VIDEO_GLYPH,
    audio: AUDIO_GLYPH,
    document: DOCUMENT_GLYPH,
    external: EXTERNAL_GLYPH,
    other: OTHER_GLYPH,
}
