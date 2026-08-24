import type { MhTheme } from './types'

/**
 * THE DEFAULT SKIN — Tailwind v4 utilities, and every one of them replaceable.
 *
 * ⚠️ TAILWIND IS THE DEFAULT, NEVER THE REQUIREMENT. A host without it replaces these strings
 * with their own and nothing here notices: the components read this table, they do not know what
 * a utility class is. What a host must not have to do is recompile the package.
 *
 * ⚠️ LITERAL COLOURS ON ANY SURFACE CARRYING TEXT. In Tailwind v4 an opacity modifier compiles
 * to `color-mix()`, which static contrast analysis cannot resolve — at which point NO text
 * colour satisfies the rule and the warning can only be silenced, never fixed. Opacity is left
 * to veils and hover states, where nothing is read.
 *
 * ⚠️ AND THE TOKENS ARE THE FIRST LEVER, NOT THIS TABLE. `--mh-*` custom properties cover the
 * ordinary case — a brand colour, a radius — from one line of CSS at the host, with no build at
 * all. This table is for the day the shape of the skin itself has to change.
 */
export const defaultTheme: MhTheme = {
    thumbnail: {
        root: {
            layout: 'relative block shrink-0 overflow-hidden',
            class: 'rounded-md bg-[var(--mh-color-muted)]',
        },
        image: {
            layout: 'h-full w-full object-cover',
            class: '',
        },
        fallback: {
            layout: 'flex h-full w-full items-center justify-center',
            class: 'text-[var(--mh-color-muted-foreground)]',
        },
        label: {
            layout: 'select-none text-xs font-semibold uppercase',
            class: '',
        },
    },

    emptyState: {
        root: {
            layout: 'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
            class: 'text-[var(--mh-color-muted-foreground)]',
        },
        icon: {
            layout: 'flex h-12 w-12 items-center justify-center rounded-full',
            class: 'bg-[var(--mh-color-muted)]',
        },
        title: {
            layout: 'text-base font-medium',
            class: 'text-[var(--mh-color-foreground)]',
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
            class: '',
        },
        item: {
            layout: 'h-full w-full animate-pulse rounded-md',
            class: 'bg-[var(--mh-color-muted)]',
        },
    },

    errorState: {
        root: {
            layout: 'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
            class: 'text-[var(--mh-color-foreground)]',
        },
        title: {
            layout: 'text-base font-medium',
            class: '',
        },
        message: {
            layout: 'max-w-sm text-sm',
            class: 'text-[var(--mh-color-muted-foreground)]',
        },
        retry: {
            layout: 'mt-2 inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent)] text-[var(--mh-color-accent-foreground)] hover:opacity-90',
        },
    },

    confirmDialog: {
        root: {
            layout: 'w-full max-w-md rounded-lg p-0 backdrop:bg-black/50',
            class: 'bg-[var(--mh-color-surface)] text-[var(--mh-color-foreground)] shadow-lg',
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
            class: 'text-[var(--mh-color-muted-foreground)]',
        },
        actions: {
            layout: 'flex items-center justify-end gap-2 px-6 pb-6',
            class: '',
        },
        cancel: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-muted)] text-[var(--mh-color-foreground)] hover:opacity-90',
        },
        confirm: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-accent)] text-[var(--mh-color-accent-foreground)] hover:opacity-90',
        },
        confirmDestructive: {
            layout: 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium',
            class: 'bg-[var(--mh-color-danger)] text-[var(--mh-color-danger-foreground)] hover:opacity-90',
        },
    },
}
