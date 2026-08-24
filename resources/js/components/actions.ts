import { computed, toValue } from 'vue'
import type { ComputedRef, MaybeRefOrGetter } from 'vue'
import type { MediaHubClient, Selection } from '../client'

/**
 * WHAT CAN BE DONE TO A SELECTION, DESCRIBED AS DATA.
 *
 * ⚠️ THE TOOLBAR AND THE CONTEXT MENU RENDER THE SAME LIST, and that is the point of this file.
 * Two lists that merely look alike diverge at the first addition: somebody adds "Duplicate" to
 * the bar, forgets the menu, and nothing turns red — the screen still works, it simply offers
 * less depending on where you clicked. A test compares what the two render for the same
 * selection, which is what makes the rule enforceable rather than merely stated.
 *
 * ⚠️ AND A HOST CAN ADD THEIR OWN. "Publish", "Send to the client", "Mark as approved" belong to
 * their trade and not to a media library; a hardcoded list would leave them writing a second
 * toolbar beside ours, which is the first step towards replacing both.
 */
export interface MhAction {
    /** Stable across versions: a host disabling or reordering actions refers to this. */
    id: string
    label: string

    /**
     * ⚠️ ASKED, NOT ASSUMED. Restoring only makes sense in the trash, purging only on something
     * already trashed. An action that is always offered and sometimes fails teaches people that
     * the buttons lie.
     */
    available?(selection: Selection): boolean

    /**
     * ⚠️ THE LOOK FOLLOWS THE CONSEQUENCE, not the wording. "Empty the trash" is destructive
     * whatever it is called, and the button should say so before it is read.
     */
    destructive?: boolean

    /** Present means: ask first. Absent means: do it. */
    confirm?: { title: string; message?: string }

    run(selection: Selection): Promise<unknown>
}

export interface UseMediaActionList {
    /** Everything, in order — including what is currently unavailable. */
    all: ComputedRef<MhAction[]>
    /** What applies to the selection at hand. */
    available: ComputedRef<MhAction[]>
}

function isEmpty(selection: Selection): boolean {
    return (selection.media?.length ?? 0) + (selection.folders?.length ?? 0) === 0
}

/**
 * THE ACTIONS THIS PACKAGE SHIPS.
 *
 * ⚠️ EMPTYING THE TRASH IS NOT HERE, deliberately. It takes no selection, so a list keyed on one
 * would have to special-case it — and a special case in a shared list is how the two renderers
 * start to differ again.
 */
export function defaultActions(client: MediaHubClient): MhAction[] {
    return [
        {
            id: 'trash',
            label: 'Move to trash',
            destructive: true,
            confirm: {
                title: 'Move to the trash?',
                message: 'They can be restored from the trash afterwards.',
            },
            available: (selection) => !isEmpty(selection),
            run: (selection) => client.trash(selection),
        },
        {
            id: 'restore',
            label: 'Restore',
            available: (selection) => !isEmpty(selection),
            run: (selection) => client.restore(selection),
        },
        {
            id: 'purge',
            label: 'Delete permanently',
            destructive: true,
            /*
             * ⚠️ THE ONLY ACTION HERE THAT CANNOT BE UNDONE, and the wording of the question says
             * so rather than asking "are you sure?" — which is what everybody clicks through.
             */
            confirm: {
                title: 'Delete permanently?',
                message: 'This cannot be undone, and the files are removed from the storage.',
            },
            available: (selection) => !isEmpty(selection),
            run: (selection) => client.purge(selection),
        },
    ]
}

/**
 * ⚠️ THE HOST'S ACTIONS REPLACE OURS BY IDENTIFIER, and are appended otherwise. Without that,
 * changing the wording of "Move to trash" would mean offering it twice.
 */
export function useMediaActionList(
    client: MediaHubClient,
    selection: MaybeRefOrGetter<Selection>,
    extra?: MaybeRefOrGetter<MhAction[] | undefined>,
): UseMediaActionList {
    const all = computed<MhAction[]>(() => {
        const merged = [...defaultActions(client)]

        for (const action of toValue(extra) ?? []) {
            const index = merged.findIndex((candidate) => candidate.id === action.id)

            if (index === -1) {
                merged.push(action)
            } else {
                merged[index] = action
            }
        }

        return merged
    })

    const available = computed<MhAction[]>(() => {
        const current = toValue(selection)

        return all.value.filter((action) => action.available?.(current) ?? true)
    })

    return { all, available }
}
