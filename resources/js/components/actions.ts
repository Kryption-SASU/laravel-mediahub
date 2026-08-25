import { computed, toValue } from 'vue'
import type { ComputedRef, MaybeRefOrGetter } from 'vue'
import type { MediaHubClient, Selection } from '../client'
import { useMediaText } from '../i18n/context'
import type { MhTranslator } from '../i18n/context'

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
/**
 * WHERE THE SCREEN IS, WHICH DECIDES WHAT CAN BE OFFERED THERE.
 *
 * ⚠️ EACH OF THE TWO SIDES DROPS THE ONE ENTRY THAT MEANS NOTHING ON IT. Putting away what is
 * already away is refused by the operation itself — re-stamping its deletion instant would
 * attach it to this one and bring it back with the next restore. Taking back what was never
 * thrown away changes nothing at all. Both would sit on a menu doing nothing, and a screen whose
 * entries do nothing teaches people to stop reading them.
 *
 * ⚠️ DELETING FOR GOOD IS ON BOTH SIDES, and that is not an oversight: skipping the trash is a
 * legitimate thing to ask for, and it works exactly the same from either.
 *
 * ⚠️ AND THE SELECTION CANNOT ANSWER THIS. It holds identifiers; whether they are in the trash is
 * a fact about the view somebody is looking at, not about the keys they ticked.
 */
export interface MhActionContext {
    /** Whether the screen is showing the trash. */
    trashed: boolean
}

/** What a confirmation puts to somebody. */
export interface MhConfirmation {
    title: string
    message?: string
}

export interface MhAction {
    /** Stable across versions: a host disabling or reordering actions refers to this. */
    id: string
    label: string

    /**
     * ⚠️ ASKED, NOT ASSUMED. Restoring only makes sense in the trash, purging only on something
     * already trashed. An action that is always offered and sometimes fails teaches people that
     * the buttons lie.
     */
    available?(selection: Selection, where: MhActionContext): boolean

    /**
     * ⚠️ THE LOOK FOLLOWS THE CONSEQUENCE, not the wording. "Empty the trash" is destructive
     * whatever it is called, and the button should say so before it is read.
     */
    destructive?: boolean

    /**
     * Present means: ask first. Absent means: do it.
     *
     * ⚠️ IT MAY BE A FUNCTION, BECAUSE SOME QUESTIONS CANNOT BE WRITTEN IN ADVANCE. Trashing a
     * folder takes its whole subtree, so the sentence that matters — "and the four hundred files
     * inside" — is only knowable once there is a selection to ask about, and only the server can
     * answer. A fixed string there would either stay silent about the files or warn about them
     * every time, including for the folder that holds none.
     */
    confirm?: MhConfirmation | ((selection: Selection) => MhConfirmation | Promise<MhConfirmation>)

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
 * THE QUESTION PUT BEFORE SOMETHING IRREVERSIBLE — and it names what is actually at stake.
 *
 * ⚠️ A FOLDER IS NEVER JUST A FOLDER. The server takes its whole subtree, nesting included, so
 * "move 1 folder to the trash" can mean four hundred files. Somebody who reads the count and
 * agrees has agreed to something they were told; somebody who reads "1 folder" has not.
 *
 * ⚠️ THE COUNT COMES FROM THE SERVER, because only it can walk the tree — and it walks it through
 * the scope, so the figure never describes a branch the caller cannot see.
 *
 * ⚠️ AND A SERVER THAT CANNOT ANSWER DOES NOT BLOCK THE ACTION. Failing to count is not a reason
 * to refuse a deletion: the question is put in its plain form, which is what it used to be in
 * every case. A confirmation that errors instead of asking would make an unreachable server look
 * like a broken button.
 */
async function warn(
    client: MediaHubClient,
    t: MhTranslator,
    selection: Selection,
    kind: 'trash' | 'purge',
): Promise<MhConfirmation> {
    const plain = {
        title: t('actions.' + kind + '.confirmTitle'),
        message: t('actions.' + kind + '.confirmMessage'),
    }

    if ((selection.folders?.length ?? 0) === 0) {
        return plain
    }

    try {
        const carried = await client.contents(selection)

        if (carried.media === 0) {
            return plain
        }

        return {
            title: plain.title,
            message: t('actions.' + kind + '.confirmInside', {}, carried.media),
        }
    } catch {
        return plain
    }
}

/**
 * THE ACTIONS THIS PACKAGE SHIPS.
 *
 * ⚠️ THE TRANSLATOR IS AN ARGUMENT, AND IT IS REQUIRED. These labels used to be English strings
 * typed here, beside a catalogue that already carried `actions.trash`, `actions.restore` and
 * `actions.purge` in every shipped language — written, and read by nobody. The result was a
 * French back-office whose only English words were the three on the menu that deletes files.
 * Made optional, the same thing would happen again the first time somebody called this without
 * one; required, the compiler asks.
 *
 * ⚠️ EMPTYING THE TRASH IS NOT HERE, deliberately. It takes no selection, so a list keyed on one
 * would have to special-case it — and a special case in a shared list is how the two renderers
 * start to differ again.
 */
export function defaultActions(client: MediaHubClient, t: MhTranslator): MhAction[] {
    return [
        {
            id: 'trash',
            label: t('actions.trash'),
            destructive: true,
            confirm: (selection) => warn(client, t, selection, 'trash'),
            available: (selection, where) => !isEmpty(selection) && !where.trashed,
            run: (selection) => client.trash(selection),
        },
        {
            id: 'restore',
            label: t('actions.restore'),
            available: (selection, where) => !isEmpty(selection) && where.trashed,
            run: (selection) => client.restore(selection),
        },
        {
            id: 'purge',
            label: t('actions.purge'),
            destructive: true,
            /*
             * ⚠️ THE ONLY ACTION HERE THAT CANNOT BE UNDONE, and the wording of the question says
             * so rather than asking "are you sure?" — which is what everybody clicks through.
             */
            confirm: (selection) => warn(client, t, selection, 'purge'),
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
    where?: MaybeRefOrGetter<MhActionContext>,
): UseMediaActionList {
    /*
     * ⚠️ READ INSIDE THE COMPUTED, NOT CAPTURED ONCE. The translator closes over the locale the
     * provider holds, so calling it here is what makes the list follow a language switched at
     * runtime — labels built at setup would stay in whatever language was current at mount.
     */
    const t = useMediaText()

    const all = computed<MhAction[]>(() => {
        const merged = [...defaultActions(client, t)]

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

        /* ⚠️ OUTSIDE THE TRASH BY DEFAULT. A caller that says nothing is looking at a library,
         * which is the ordinary case — and the answer has to be one of the two, since "somewhere
         * unspecified" would make every action either always shown or never. */
        const context = toValue(where) ?? { trashed: false }

        return all.value.filter((action) => action.available?.(current, context) ?? true)
    })

    return { all, available }
}
