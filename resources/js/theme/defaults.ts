import type { MhTheme } from './types'

/**
 * THE DEFAULT SKIN — Tailwind v4 utilities, and every one of them replaceable.
 *
 * ⚠️ TAILWIND IS THE DEFAULT, NEVER THE REQUIREMENT. A host without it replaces these strings
 * with their own and nothing here notices: the components read this table, they do not know what
 * a utility class is. What a host must not have to do is recompile the package.
 *
 * ⚠️ EVERY TOKEN CARRIES ITS OWN VALUE AS A FALLBACK, and that is not decoration. `mediahub.css`
 * is a file a host has to import; forgetting it used to leave every colour here resolving to an
 * undefined custom property, which makes the declaration invalid at computed-value time — the
 * background silently becomes transparent, the ring falls back to `currentColor`, and the screen
 * comes out looking like a broken package rather than a missing import. Measured on a real host
 * on 25/08/2026: the shape was right, the skin was gone, and nothing anywhere said why. With the
 * value written twice, the default skin stands on its own and the token file goes back to being
 * what it claims to be — a lever, not a prerequisite.
 *
 * ⚠️ LITERAL COLOURS ON ANY SURFACE CARRYING TEXT. In Tailwind v4 an opacity modifier compiles
 * to `color-mix()`, which static contrast analysis cannot resolve — at which point NO text
 * colour satisfies the rule and the warning can only be silenced, never fixed. Opacity is left
 * to veils and hover states, where nothing is read.
 *
 * ⚠️ AND THE TOKENS ARE STILL THE FIRST LEVER, NOT THIS TABLE. `--mh-*` custom properties cover
 * the ordinary case — a brand colour, a radius — from one line of CSS at the host, with no build
 * at all. This table is for the day the shape of the skin itself has to change.
 */
export const defaultTheme: MhTheme = {
    thumbnail: {
        root: {
            layout: 'relative block shrink-0 overflow-hidden',
            class: 'rounded-md bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        image: {
            layout: 'h-full w-full object-cover',
            class: '',
        },
        fallback: {
            layout: 'flex h-full w-full flex-col items-center justify-center gap-1',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        /*
         * ⚠️ SIZED IN FRACTIONS OF THE FRAME, NOT IN PIXELS. The same component draws a 3rem
         * chip beside a form field and a 200px tile in the grid; a fixed icon fills one and
         * disappears in the other.
         */
        icon: {
            layout: 'h-1/3 w-1/3',
            class: '',
        },
        label: {
            layout: 'select-none text-xs font-semibold uppercase',
            class: '',
        },
    },

    emptyState: {
        root: {
            layout: 'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        icon: {
            layout: 'flex h-12 w-12 items-center justify-center rounded-full',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        title: {
            layout: 'text-base font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        description: {
            layout: 'max-w-sm text-sm',
            class: '',
        },
        actions: {
            layout: 'mt-2 flex items-center gap-2',
            class: '',
        },
    },

    skeleton: {
        root: {
            layout: 'grid gap-3',
            class: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6',
        },
        item: {
            layout: 'w-full animate-pulse rounded-md',
            class: 'aspect-square bg-[var(--mh-color-muted,#f1f5f9)]',
        },
    },

    errorState: {
        root: {
            layout: 'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        title: {
            layout: 'text-base font-medium',
            class: '',
        },
        message: {
            layout: 'max-w-sm text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        retry: {
            layout: 'mt-2 inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)] hover:opacity-90',
        },
    },

    /*
     * ⚠️ `m-auto` ON EVERY DIALOG, AND IT IS STRUCTURE RATHER THAN TASTE. A modal `<dialog>` is
     * centred by the browser's own stylesheet, with `margin: auto` against `inset: 0` — and
     * Tailwind's preflight resets the margin of every element to zero, which takes that centring
     * away and leaves the prompt pinned to the top-left corner of the window. Nothing warns: the
     * backdrop still appears, the focus trap still works, and the box is simply in the wrong
     * place. It lives in `layout` so that a host restyling the surface cannot lose it.
     */
    confirmDialog: {
        root: {
            layout: 'm-auto w-full max-w-md rounded-lg p-0 backdrop:bg-black/50',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow-lg',
        },
        body: {
            layout: 'flex flex-col gap-2 p-6',
            class: '',
        },
        title: {
            layout: 'text-base font-semibold',
            class: '',
        },
        message: {
            layout: 'text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        actions: {
            layout: 'flex items-center justify-end gap-2 px-6 pb-6',
            class: '',
        },
        cancel: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        confirm: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)] hover:opacity-90',
        },
        confirmDestructive: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-danger,#b91c1c)] text-[var(--mh-color-danger-foreground,#ffffff)] hover:opacity-90',
        },
    },

    itemCard: {
        /*
         * ⚠️ `group` AND `relative` ARE STRUCTURE, NOT DECORATION. The menu button is positioned
         * against this box and revealed by hovering it; a host replacing the skin and dropping
         * either would leave that control stuck in the corner of the page, or permanently on.
         */
        root: {
            layout: 'group relative flex cursor-pointer flex-col gap-2 p-2 focus:outline-none',
            class: 'rounded-md bg-[var(--mh-color-surface,#ffffff)] ring-1 ring-[var(--mh-color-muted,#f1f5f9)] hover:ring-[var(--mh-color-accent,#1d4ed8)]',
        },
        /*
         * ⚠️ HIDDEN BY OPACITY RATHER THAN BY `hidden`, and shown on focus as well as on hover.
         * Removed from the layout it would shift the tile as the pointer arrives; removed from
         * the accessibility tree it would be a control only a mouse knows about.
         */
        menu: {
            layout: 'absolute right-2 top-2 z-10 inline-flex h-7 w-7 items-center justify-center rounded opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 focus:opacity-100',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        menuIcon: {
            layout: 'h-4 w-4',
            class: '',
        },
        /*
         * ⚠️ BEING CHOSEN HAS TO BE SOMETHING YOU CAN SEE, not something you infer from a ring
         * two pixels wider than the resting one. Somebody ticking six files out of forty should
         * be able to check their work by looking, and a difference at the edge of a tile does
         * not survive a photograph behind it.
         */
        tick: {
            layout: 'absolute left-2 top-2 z-10 inline-flex h-6 w-6 items-center justify-center rounded-full',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        tickIcon: {
            layout: 'h-4 w-4',
            class: '',
        },
        /*
         * ⚠️ THE SELECTED STATE IS A SEPARATE ENTRY, NOT A COLOUR SWAPPED INTO `root`. A host
         * replacing the resting skin would otherwise take the selected one with it, and end up
         * with a grid where nothing looks chosen.
         */
        selected: {
            layout: '',
            class: 'ring-2 ring-[var(--mh-color-accent,#1d4ed8)]',
        },
        /*
         * ⚠️ THE FRAME IS WHAT GIVES THE THUMBNAIL A HEIGHT, and without it the grid is a
         * staircase. The thumbnail is asked for `100%` of its parent; in a column with no height
         * of its own that resolves to the picture's own dimensions, so a portrait wallpaper
         * became a 450px tile beside a 60px one. The ratio lives in `class` because it is a
         * choice — a host wanting 4:3, or 16:9 for a video library, changes this one string.
         */
        preview: {
            layout: 'block w-full overflow-hidden',
            class: 'aspect-square rounded bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        name: {
            layout: 'truncate text-center text-xs',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
    },

    itemGrid: {
        /*
         * ⚠️ THE COLUMN COUNT IS SKIN, NOT STRUCTURE. Density is the first thing a host argues
         * with, and leaving it in `layout` would mean restating `grid gap-3` to change it.
         */
        root: {
            layout: 'grid gap-3',
            class: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6',
        },
        empty: {
            layout: 'flex items-center justify-center',
            class: '',
        },
    },

    mediaPicker: {
        root: {
            layout: 'm-auto w-full max-w-3xl rounded-lg p-0 backdrop:bg-black/50',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow-lg',
        },
        header: {
            layout: 'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between',
            class: 'border-b border-[var(--mh-color-muted,#f1f5f9)]',
        },
        title: {
            layout: 'text-base font-semibold',
            class: '',
        },
        search: {
            layout: 'flex items-center gap-2',
            class: '',
        },
        searchLabel: {
            layout: 'text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        searchInput: {
            layout: 'rounded-md px-2 py-1 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        body: {
            layout: 'max-h-[60vh] overflow-y-auto p-4',
            class: '',
        },
        actions: {
            layout: 'flex items-center justify-end gap-2 p-4',
            class: 'border-t border-[var(--mh-color-muted,#f1f5f9)]',
        },
        cancel: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        confirm: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
    },

    mediaInput: {
        root: {
            layout: 'flex flex-col gap-2',
            class: '',
        },
        label: {
            layout: 'text-sm font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        preview: {
            layout: 'flex items-center gap-3',
            class: '',
        },
        empty: {
            layout: 'text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        name: {
            layout: 'truncate text-sm',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        actions: {
            layout: 'flex items-center gap-2',
            class: '',
        },
        choose: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        clear: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
    },

    mediaGallery: {
        root: {
            layout: 'flex flex-col gap-2',
            class: '',
        },
        label: {
            layout: 'text-sm font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        empty: {
            layout: 'text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        list: {
            layout: 'flex flex-col gap-2',
            class: '',
        },
        item: {
            layout: 'flex items-center gap-3 p-2',
            class: 'rounded-md ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        unknown: {
            layout: 'inline-block h-16 w-16 rounded-md',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        name: {
            layout: 'grow truncate text-sm',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        moveUp: {
            layout: 'inline-flex items-center rounded-md px-2 py-1 text-xs disabled:opacity-50',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        moveDown: {
            layout: 'inline-flex items-center rounded-md px-2 py-1 text-xs disabled:opacity-50',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        remove: {
            layout: 'inline-flex items-center rounded-md px-2 py-1 text-xs disabled:opacity-50',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        add: {
            layout: 'inline-flex w-fit items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
    },

    selectionBar: {
        root: {
            layout: 'flex flex-wrap items-center gap-3 p-3',
            class: 'rounded-md bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        count: {
            layout: 'text-sm font-semibold',
            class: '',
        },
        actions: {
            layout: 'flex flex-wrap items-center gap-2',
            class: '',
        },
        action: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        destructive: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-danger,#b91c1c)] text-[var(--mh-color-danger-foreground,#ffffff)]',
        },
        clear: {
            layout: 'ml-auto inline-flex items-center rounded-md px-3 py-2 text-sm',
            class: 'text-[var(--mh-color-muted-foreground,#475569)] hover:opacity-90',
        },
    },

    contextMenu: {
        root: {
            layout: 'fixed z-50 flex min-w-48 flex-col p-1',
            class: 'rounded-md bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow-lg ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        item: {
            layout: 'flex w-full items-center rounded px-3 py-2 text-left text-sm disabled:opacity-50',
            class: 'hover:bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        destructive: {
            layout: 'flex w-full items-center rounded px-3 py-2 text-left text-sm disabled:opacity-50',
            class: 'text-[var(--mh-color-danger,#b91c1c)] hover:bg-[var(--mh-color-muted,#f1f5f9)]',
        },
    },

    /*
     * THE DROP TARGET IS THE LISTING ITSELF — not a dashed box parked above it.
     *
     * ⚠️ A PERMANENT DASHED RECTANGLE IS AN ADVERT, NOT AN AFFORDANCE. It occupied the width of
     * the screen at rest, pushed the files down, and still only accepted a drop on its own few
     * hundred pixels: everywhere the eye actually goes — onto the grid — the browser opened the
     * file and threw the page away. Wrapping the listing makes the whole area accept it, and
     * costs nothing on screen until somebody is holding something.
     */
    dropzone: {
        root: {
            layout: 'relative flex flex-col gap-4',
            class: '',
        },
        /*
         * ⚠️ A SEPARATE ENTRY RATHER THAN A CLASS APPENDED WHILE DRAGGING. A host replacing
         * the resting skin would otherwise keep our highlight, and the two would clash on
         * precisely the frame somebody is looking at.
         */
        active: {
            layout: 'relative flex flex-col gap-4',
            class: 'rounded-md outline-2 outline-dashed outline-offset-4 outline-[var(--mh-color-accent,#1d4ed8)]',
        },
        /*
         * ⚠️ `pointer-events-none` IS LOAD-BEARING. The veil covers the very area the file is
         * being dropped onto; catching the pointer would put it between the cursor and the
         * listener, and the drop would be swallowed by the thing that announced it.
         */
        veil: {
            layout: 'pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-1 rounded-md',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        label: {
            layout: 'text-sm font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        hint: {
            layout: 'text-xs',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
    },

    /*
     * THE KEYBOARD ROUTE TO AN UPLOAD, AND IT IS A SEPARATE COMPONENT ON PURPOSE. Dragging
     * cannot be done from a keyboard, is awkward with a screen reader and is impossible on most
     * touch devices — so the file input is not a detail of the drop zone, it is the primary
     * control, and it belongs in the toolbar where a primary control is looked for.
     */
    uploadButton: {
        root: {
            layout: 'inline-flex items-center',
            class: '',
        },
        /*
         * ⚠️ THE LABEL IS THE BUTTON, AND THE INPUT IS STILL THERE. `sr-only` moves it out of
         * sight, NOT out of the accessibility tree and not out of the tab order: it stays
         * focusable, announced, and operable with the keyboard. `hidden` or `display:none` would
         * take all three away and leave a control only a mouse can reach.
         */
        label: {
            layout: 'inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)] hover:opacity-90',
        },
        /*
         * ⚠️ `sr-only` RATHER THAN NO TEXT AT ALL. A toolbar of glyphs reads as a tool; a toolbar
         * of glyphs with nothing to announce reads as a row of unnamed buttons to anybody not
         * looking at it. The word stays in one place, in every language, and a host that wants it
         * on screen replaces this one string.
         */
        wording: {
            layout: 'sr-only',
            class: '',
        },
        icon: {
            layout: 'h-5 w-5 shrink-0',
            class: '',
        },
        input: {
            layout: 'sr-only',
            class: '',
        },
    },

    folderCreator: {
        root: {
            layout: 'inline-flex items-center',
            class: '',
        },
        trigger: {
            layout: 'inline-flex h-9 w-9 items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        /* ⚠️ See `uploadButton.wording`: hidden, never absent. */
        wording: {
            layout: 'sr-only',
            class: '',
        },
        icon: {
            layout: 'h-5 w-5 shrink-0',
            class: '',
        },
        dialog: {
            layout: 'm-auto w-full max-w-sm rounded-lg p-0 backdrop:bg-black/50',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow-lg',
        },
        body: {
            layout: 'flex flex-col gap-2 p-6',
            class: '',
        },
        title: {
            layout: 'text-base font-semibold',
            class: '',
        },
        label: {
            layout: 'text-xs font-medium',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        input: {
            layout: 'rounded-md px-3 py-2 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        actions: {
            layout: 'flex items-center justify-end gap-2 px-6 pb-6',
            class: '',
        },
        cancel: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        submit: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
    },

    uploadQueue: {
        root: {
            layout: 'flex flex-col gap-2 p-3',
            class: 'rounded-md bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        header: {
            layout: 'flex items-center justify-between',
            class: '',
        },
        title: {
            layout: 'text-sm font-semibold',
            class: '',
        },
        summary: {
            layout: 'text-xs',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        list: {
            layout: 'flex flex-col gap-2',
            class: '',
        },
        item: {
            layout: 'flex flex-wrap items-center gap-2',
            class: '',
        },
        name: {
            layout: 'grow truncate text-sm',
            class: '',
        },
        progress: {
            layout: 'h-2 w-32',
            class: '',
        },
        status: {
            layout: 'text-xs uppercase',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        error: {
            layout: 'text-xs',
            class: 'text-[var(--mh-color-danger,#b91c1c)]',
        },
        abort: {
            layout: 'inline-flex items-center rounded px-2 py-1 text-xs',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        retry: {
            layout: 'inline-flex items-center rounded px-2 py-1 text-xs',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        clear: {
            layout: 'w-fit rounded px-2 py-1 text-xs',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
    },

    quotaMeter: {
        root: {
            layout: 'flex items-center gap-2',
            class: '',
        },
        label: {
            layout: 'text-xs font-medium',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        meter: {
            layout: 'h-2 w-24',
            class: '',
        },
        summary: {
            layout: 'text-xs',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
    },
    detailsDialog: {
        root: {
            layout: 'm-auto w-full max-w-lg rounded-lg p-0 backdrop:bg-black/50',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow-lg',
        },
        body: {
            layout: 'flex flex-col',
            class: '',
        },
        header: {
            layout: 'flex items-start justify-between gap-3 px-4 pt-4',
            class: '',
        },
        title: {
            layout: 'min-w-0 truncate text-base font-semibold',
            class: '',
        },
        close: {
            layout: 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md',
            class: 'text-[var(--mh-color-muted-foreground,#475569)] hover:bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        closeIcon: {
            layout: 'h-5 w-5',
            class: '',
        },
        /*
         * ⚠️ THE PANEL WEARS NOTHING INSIDE THE WINDOW. On its own it is a card — a surface,
         * a ring, a radius — because in a column it has to be told apart from the grid beside
         * it. Inside a window that surface is already there, and a second one reads as a box
         * drawn in a box. What is passed down is this string, so a host can put one back.
         */
        panel: {
            layout: '',
            class: '',
        },
    },
    detailsPanel: {
        /*
         * ⚠️ A COLUMN OF ITS OWN FROM `lg` UP, and it does not shrink. A details panel that
         * borrows its width from the grid beside it changes size every time somebody picks a
         * file with a longer name, and the grid reflows underneath the click that caused it.
         */
        root: {
            layout: 'flex w-full flex-col gap-3 p-4',
            class: 'rounded-md bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        /*
         * ⚠️ THE RESTING STATE IS RENDERED, NOT OMITTED. A panel that only exists once something
         * is chosen makes the grid jump sideways on the first click, and gives no clue beforehand
         * that choosing a file will show anything at all.
         */
        empty: {
            layout: 'flex w-full flex-col items-center justify-center gap-1 p-6 text-center',
            class: 'rounded-md text-[var(--mh-color-muted-foreground,#475569)] ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        emptyTitle: {
            layout: 'text-sm font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        emptyHint: {
            layout: 'text-xs',
            class: '',
        },
        preview: {
            layout: 'block w-full overflow-hidden',
            class: 'aspect-square rounded bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        link: {
            layout: 'flex items-center gap-1',
            class: '',
        },
        /*
         * ⚠️ `min-w-0` ON THE FIELD. An input has an intrinsic width of about twenty characters
         * and refuses to go below it inside a flex row, so a long address pushes the copy button
         * out of the panel rather than scrolling inside its own box.
         */
        linkInput: {
            layout: 'w-full min-w-0 rounded-md px-2 py-1 text-xs',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        /*
         * ⚠️ A DRAWING RATHER THAN A WORD, and the word moves to the label. "Copy" beside a field
         * holding a URL is three characters wider than the field can spare on a narrow panel, and
         * it says in text what an icon says at a glance. What it must never lose is the label:
         * an icon button with nothing to announce is a button a screen reader calls "button".
         */
        copy: {
            layout: 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        copyIcon: {
            layout: 'h-4 w-4',
            class: '',
        },
        copied: {
            layout: 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        use: {
            layout: 'w-full rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)] hover:opacity-90',
        },
        facts: {
            layout: 'grid grid-cols-2 gap-2 text-sm',
            class: '',
        },
        fact: {
            layout: 'flex flex-col',
            class: '',
        },
        /*
         * ⚠️ A TERM IS CONTENT, NOT DECORATION. Set in the muted tone it was the palest thing on
         * a panel of pale things — uppercase and small on top of that — and the words naming each
         * fact were harder to read than the facts. Reported from a real screen on 25/08/2026.
         */
        term: {
            layout: 'text-xs font-semibold uppercase',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        value: {
            layout: 'truncate',
            class: '',
        },
        field: {
            layout: 'flex flex-col gap-1',
            class: '',
        },
        label: {
            layout: 'text-xs font-semibold',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        input: {
            layout: 'rounded-md px-2 py-1 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        save: {
            layout: 'w-fit rounded-md px-3 py-2 text-sm font-medium disabled:opacity-50',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
    },
    breadcrumb: {
        root: {
            layout: 'text-sm',
            class: '',
        },
        list: {
            layout: 'flex flex-wrap items-center gap-1',
            class: '',
        },
        item: {
            layout: 'flex items-center gap-1',
            class: '',
        },
        link: {
            layout: 'rounded px-1 underline-offset-2 hover:underline',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        current: {
            layout: 'px-1 font-medium',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        separator: {
            layout: 'select-none',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
    },

    /*
     * ONE ROW: WHAT YOU CAN DO ON THE LEFT, WHAT YOU CAN NARROW ON THE RIGHT.
     *
     * ⚠️ THE SEARCH IS PUSHED TO THE END WITH `ml-auto` RATHER THAN PLACED THERE. A toolbar that
     * wraps on a narrow screen would otherwise leave the search box stranded in the middle of a
     * row of buttons; pushed, it either sits at the end or starts a line of its own.
     */
    toolbar: {
        root: {
            layout: 'flex flex-wrap items-center gap-3',
            class: '',
        },
        start: {
            layout: 'flex flex-wrap items-center gap-2',
            class: '',
        },
        filters: {
            layout: 'flex flex-wrap items-center gap-2',
            class: '',
        },
        search: {
            layout: 'ml-auto flex items-center gap-2',
            class: '',
        },
        group: {
            layout: 'flex items-center gap-1',
            class: '',
        },
        label: {
            layout: 'text-xs font-medium',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        input: {
            layout: 'rounded-md px-3 py-2 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        select: {
            layout: 'rounded-md px-3 py-2 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
        direction: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)]',
        },
    },

    /*
     * FOLDERS WEAR THE SAME TILE AS FILES, AND SIT IN THE SAME COLUMNS.
     *
     * ⚠️ THE SAME LOOK, DELIBERATELY NOT THE SAME LISTBOX. A folder in the grid's own
     * `role="listbox"` would have to answer Space and Enter in two different ways, one keystroke
     * apart. Two containers with one geometry gives the eye a single grid and the keyboard two
     * unambiguous behaviours.
     */
    folderList: {
        root: {
            layout: 'w-full',
            class: '',
        },
        list: {
            layout: 'grid gap-3',
            class: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6',
        },
        /* ⚠️ THE LIST ITEM CARRIES THE POSITIONING, because the tile is a button and the menu
         * cannot live inside one. See `itemCard.root` for the same two classes and the same
         * reason. */
        entry: {
            layout: 'group relative',
            class: '',
        },
        menu: {
            layout: 'absolute right-2 top-2 z-10 inline-flex h-7 w-7 items-center justify-center rounded opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 focus:opacity-100',
            class: 'bg-[var(--mh-color-surface,#ffffff)] text-[var(--mh-color-foreground,#0f172a)] shadow ring-1 ring-[var(--mh-color-muted,#f1f5f9)]',
        },
        menuIcon: {
            layout: 'h-4 w-4',
            class: '',
        },
        /*
         * ⚠️ BEING CHOSEN HAS TO BE SOMETHING YOU CAN SEE, not something you infer from a ring
         * two pixels wider than the resting one. Somebody ticking six files out of forty should
         * be able to check their work by looking, and a difference at the edge of a tile does
         * not survive a photograph behind it.
         */
        tick: {
            layout: 'absolute left-2 top-2 z-10 inline-flex h-6 w-6 items-center justify-center rounded-full',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        tickIcon: {
            layout: 'h-4 w-4',
            class: '',
        },
        selected: {
            layout: '',
            class: 'ring-2 ring-[var(--mh-color-accent,#1d4ed8)]',
        },
        item: {
            layout: 'flex w-full cursor-pointer flex-col gap-2 p-2 focus:outline-none',
            class: 'rounded-md bg-[var(--mh-color-surface,#ffffff)] ring-1 ring-[var(--mh-color-muted,#f1f5f9)] hover:ring-[var(--mh-color-accent,#1d4ed8)]',
        },
        preview: {
            layout: 'flex w-full items-center justify-center overflow-hidden',
            class: 'aspect-square rounded bg-[var(--mh-color-muted,#f1f5f9)]',
        },
        icon: {
            layout: 'h-1/3 w-1/3',
            class: 'text-[var(--mh-color-muted-foreground,#475569)]',
        },
        name: {
            layout: 'truncate text-center text-xs',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
    },

    mediaLibrary: {
        /*
         * ⚠️ A TOGGLE READS AS PRESSED OR IT READS AS NOTHING. Selection mode changes what a
         * click does everywhere on the screen; if the control that turned it on looks exactly
         * like the one that turns it off, the only way to tell which state you are in is to
         * click something and see what happens.
         */
        picking: {
            layout: 'inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        pickingOn: {
            layout: 'inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        /*
         * ⚠️ SQUARE AND WORDLESS, AND IT STILL HAS TO ANNOUNCE ITSELF. The trash is one glyph
         * everybody recognises, so the label is spent on the two other buttons; what it must
         * never lose is `aria-label`, or it is a button a screen reader calls "button".
         */
        trash: {
            layout: 'inline-flex h-9 w-9 items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-muted,#f1f5f9)] text-[var(--mh-color-foreground,#0f172a)] hover:opacity-90',
        },
        trashOn: {
            layout: 'inline-flex h-9 w-9 items-center justify-center rounded-md',
            class: 'bg-[var(--mh-color-accent,#1d4ed8)] text-[var(--mh-color-accent-foreground,#ffffff)]',
        },
        trashIcon: {
            layout: 'h-5 w-5',
            class: '',
        },
        root: {
            layout: 'flex flex-col gap-4',
            class: 'text-[var(--mh-color-foreground,#0f172a)]',
        },
        header: {
            layout: 'flex flex-col gap-3',
            class: '',
        },
        /*
         * ⚠️ WHERE YOU ARE AND HOW MUCH IS LEFT, ON ONE LINE. Both answer the same question —
         * "what am I looking at" — and both are read once and ignored; stacked, they cost two
         * rows of a screen whose job is to show files.
         */
        context: {
            layout: 'flex flex-wrap items-center justify-between gap-3',
            class: '',
        },
        body: {
            layout: 'flex flex-col gap-4 lg:flex-row lg:items-start',
            class: '',
        },
        main: {
            layout: 'flex min-w-0 grow flex-col gap-4',
            class: '',
        },
    },
}
