import { computed, toValue } from 'vue'
import type { ComputedRef, MaybeRefOrGetter } from 'vue'
import type { Media, MediaHubClient, Selection } from '../client'
import { MediaHubError } from '../client'
import { useMediaText } from '../i18n/context'
import type { MhTranslator } from '../i18n/context'
import { requestArchive } from './archive'
import { copyText } from './clipboard'
import { startDownload } from './download'
import {
    DOWNLOAD_GLYPH,
    ZIP_GLYPH,
    DUPLICATE_GLYPH,
    EYE_GLYPH,
    LINK_GLYPH,
    PENCIL_GLYPH,
    PULSE_GLYPH,
    RESTORE_GLYPH,
    TRASH_GLYPH,
} from './glyphs'

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

    /**
     * ⚠️ WHETHER SOMEBODY IS BUILDING A BATCH, which is a different question from what they have
     * ticked so far. Half of these entries act on exactly one thing — a file cannot be renamed
     * to two names, and duplicating four files is four acts, not one — so they are offered where
     * one thing is being pointed at and not where a batch is being assembled. Reading "one file
     * is ticked" instead would put "Rename" in the quick-action bar for as long as the batch
     * happened to hold a single file, and take it away as soon as a second was added.
     */
    picking: boolean

    /**
     * The one file being pointed at, when there is exactly one and the screen has it in hand.
     *
     * ⚠️ THE SELECTION CANNOT ANSWER THIS EITHER: it holds identifiers. Some entries depend on
     * what a file IS rather than on how many were ticked — whether the server could draw a
     * picture for it, which is a fact about the machine as much as about the type. Left to the
     * type alone, "build the thumbnail again" would be offered on every video, including on the
     * machines with no ffmpeg, and would earn a refusal for its trouble.
     *
     * ⚠️ AND IT IS OPTIONAL, because a screen that does not have the object still works: every
     * entry that needs it simply does not appear. A host rendering the menu from identifiers
     * alone loses one action, not the menu.
     */
    subject?: Media | null
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
     * The drawing beside the label, as SVG path data on the 24 grid — see `glyphs.ts`.
     *
     * ⚠️ IT BELONGS TO THE ACTION, NOT TO THE MENU. A host adding "Publish" would otherwise have
     * their entry rendered as the only wordy one in a column of drawings, and the way to fix it
     * would be to fork both renderers.
     *
     * ⚠️ AND IT IS OPTIONAL, because an entry with no drawing is worse than an entry with a bad
     * one. A host who has none simply gets a label.
     */
    icon?: readonly string[]

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

    /**
     * ⚠️ THE SECOND ARGUMENT IS OPTIONAL AND ALMOST NOTHING USES IT. Nearly every act here is
     * over in a moment and has nothing to report on the way; the archive is the exception, and
     * it is the exception because the browser refuses to say anything about a download it has
     * taken over. An act that ignores the reporter simply says nothing, and the screen shows the
     * spinner it always showed.
     */
    run(selection: Selection, report?: MhReport): Promise<unknown>
}

/** How far an act has got, in whatever unit it counts. */
export type MhReport = (done: number, total: number) => void

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
 * THE ONE FILE BEING POINTED AT, OR NOTHING.
 *
 * ⚠️ A FOLDER IN THE SELECTION MAKES THE ANSWER NOTHING, rather than being ignored. "Download"
 * offered on a file and a folder together would quietly download the file and say nothing about
 * the folder, which is the kind of half-obedience that costs somebody an afternoon.
 */
function onlyFile(selection: Selection): string | null {
    const file = selection.media?.length === 1 ? selection.media[0] : null

    return (selection.folders?.length ?? 0) === 0 ? (file ?? null) : null
}

/** Exactly one thing, of either kind. */
function onlyOne(selection: Selection): boolean {
    return (selection.media?.length ?? 0) + (selection.folders?.length ?? 0) === 1
}

/**
 * ⚠️ THE ORDINARY SCREEN: the library, with somebody pointing at one thing rather than building a
 * batch. Everything that acts on a single item is offered here and nowhere else — the trash
 * offers two things and means them, and a batch is not a place to rename.
 */
function ordinary(where: MhActionContext): boolean {
    return !where.trashed && !where.picking
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
 * WHAT A SCREEN LENDS TO THE LIST, FOR THE ENTRIES THAT CANNOT BE DATA ALONE.
 *
 * ⚠️ TWO OF THESE ACTIONS NEED SOMEWHERE TO HAPPEN. Showing a file full screen and asking for a
 * new name are not requests to a server; they are surfaces, and a table of data cannot own one.
 *
 * ⚠️ THEY ARE LENT RATHER THAN APPENDED, AND THE REASON IS THE ORDER. Actions supplied from the
 * outside land at the end of the list, which would put "Preview" and "Rename" underneath "Move
 * to trash" — the destructive entry in the middle of the ordinary ones. Declared here, the whole
 * list is written once, in the order somebody reads it.
 *
 * ⚠️ AND AN ENTRY WHOSE SURFACE IS MISSING IS NOT OFFERED AT ALL. A host rendering the menu on
 * its own screen has no viewer, so it gets no "Preview" — rather than one that does nothing,
 * which is how a screen teaches people to stop reading it.
 */
export interface MhActionSurfaces {
    /** Show one file, full screen. */
    preview?(media: string): void
    /** Ask for a new name for one file or one folder. */
    rename?(selection: Selection): void
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
export function defaultActions(
    client: MediaHubClient,
    t: MhTranslator,
    surfaces: MhActionSurfaces = {},
): MhAction[] {
    return [
        {
            id: 'preview',
            label: t('actions.preview'),
            icon: EYE_GLYPH,
            available: (selection, where) =>
                surfaces.preview !== undefined && onlyFile(selection) !== null && ordinary(where),
            run: (selection) => {
                surfaces.preview?.(onlyFile(selection)!)

                return Promise.resolve()
            },
        },
        {
            id: 'link',
            label: t('actions.link'),
            icon: LINK_GLYPH,
            available: (selection, where) => onlyFile(selection) !== null && ordinary(where),
            /*
             * ⚠️ THE ADDRESS IS ASKED FOR RATHER THAN BUILT. A URL assembled here from an
             * identifier would be right until the day a host serves its files from elsewhere —
             * a CDN, a signed route, a disk that answers with its own links — and wrong in a way
             * nobody notices until somebody pastes one.
             */
            run: async (selection) => copyText((await client.show(onlyFile(selection)!)).url),
        },
        {
            id: 'rename',
            label: t('actions.rename'),
            icon: PENCIL_GLYPH,
            /* ⚠️ A FOLDER TOO. It is the one entry here that means the same thing on both, and
             * leaving it off would make renaming a folder reachable from nowhere. */
            available: (selection, where) =>
                surfaces.rename !== undefined && onlyOne(selection) && ordinary(where),
            run: (selection) => {
                surfaces.rename?.(selection)

                return Promise.resolve()
            },
        },
        {
            id: 'duplicate',
            label: t('actions.duplicate'),
            icon: DUPLICATE_GLYPH,
            available: (selection, where) => onlyFile(selection) !== null && ordinary(where),
            /* ⚠️ NO TARGET MEANS "WHERE IT ALREADY IS". Duplicating is not moving, and the copy
             * belongs beside the original where somebody can see it happened. */
            run: (selection) => client.copy(onlyFile(selection)!, null),
        },
        {
            id: 'regenerate',
            label: t('actions.regenerate'),
            icon: PULSE_GLYPH,
            /*
             * ⚠️ OFFERED ONLY WHERE THE SERVER SAYS IT COULD DRAW SOMETHING, and that is not a
             * property of the type. The same `video/mp4` is drawable on a machine with ffmpeg and
             * not on one without: a menu written from the type alone offers the entry on half the
             * installations that exist, and earns a refusal for its trouble.
             *
             * ⚠️ AND NOT ON AN IMAGE. Its thumbnail is already made from the file itself and
             * nothing about it can have changed — the entry would do work nobody can see.
             */
            available: (selection, where) =>
                onlyFile(selection) !== null
                && where.subject?.can_draw === true
                /*
                 * ⚠️ AND NOT ON AN IMAGE. Its thumbnail is made from the file itself and nothing
                 * about the file can have changed: the entry would do work nobody can see, on the
                 * one type where it is offered most often. What it costs is the rare image whose
                 * thumbnail failed — and that is what `mediahub:conversions --missing` is for.
                 */
                && where.subject.type !== 'image'
                && ordinary(where),
            run: (selection) => client.regenerate(onlyFile(selection)!),
        },
        {
            id: 'download',
            label: t('actions.download'),
            icon: DOWNLOAD_GLYPH,
            /*
             * ⚠️ ONE FILE GOES AS ITSELF, AND ANYTHING ELSE GOES AS AN ARCHIVE. The two entries
             * below are complementary rather than alternative: on any selection outside the
             * trash, exactly one of them is offered, so there is never a moment where somebody
             * has ticked something and cannot take it away.
             *
             * ⚠️ AND THIS ONE IS OFFERED WHILE A BATCH IS BEING BUILT TOO, unlike the other
             * single-item entries. Downloading is the one act with an obvious meaning on a batch
             * of one, and refusing it there would be a rule about our own state rather than
             * about what somebody asked for.
             */
            available: (selection, where) => onlyFile(selection) !== null && ! where.trashed,
            run: async (selection) => {
                const media = await client.show(onlyFile(selection)!)

                startDownload(media.download_url, media.file_name)
            },
        },
        {
            id: 'archive',
            label: t('actions.archive'),
            icon: ZIP_GLYPH,
            /*
             * ⚠️ A FOLDER, OR MORE THAN ONE THING. Zipping a single file is a nuisance dressed as
             * a feature — somebody wanting one file wants that file — and a folder cannot be
             * downloaded any other way, since a folder is not an object that has bytes.
             */
            available: (selection, where) =>
                ! isEmpty(selection) && ! where.trashed && onlyFile(selection) === null,
            /*
             * ⚠️ NOTHING HERE EVER HOLDS THE ARCHIVE. The request is made by the browser's own
             * download machinery, because reading the answer would put a streamed ZIP back into
             * the tab's memory — and it would fail on exactly the archives that most needed
             * streaming.
             */
            run: async (selection, report) => {
                const outcome = await requestArchive(client, selection, undefined, report)

                /* ⚠️ A REFUSAL IS RAISED RATHER THAN SWALLOWED, so it reaches the same place
                 * every other failure on this screen does. The reason is the server's own key,
                 * and the catalogue turns it into a sentence. */
                if (outcome.reason !== null) {
                    throw new MediaHubError(422, outcome.reason, t('errors.' + outcome.reason))
                }
            },
        },
        {
            id: 'trash',
            label: t('actions.trash'),
            icon: TRASH_GLYPH,
            destructive: true,
            confirm: (selection) => warn(client, t, selection, 'trash'),
            available: (selection, where) => !isEmpty(selection) && !where.trashed,
            run: (selection) => client.trash(selection),
        },
        {
            id: 'restore',
            label: t('actions.restore'),
            icon: RESTORE_GLYPH,
            available: (selection, where) => !isEmpty(selection) && where.trashed,
            run: (selection) => client.restore(selection),
        },
        {
            id: 'purge',
            label: t('actions.purge'),
            icon: TRASH_GLYPH,
            destructive: true,
            /*
             * ⚠️ THE ONLY ACTION HERE THAT CANNOT BE UNDONE, and the wording of the question says
             * so rather than asking "are you sure?" — which is what everybody clicks through.
             *
             * ⚠️ AND IT LIVES IN THE TRASH ALONE. It used to sit on the ordinary menu beside
             * "Move to trash", one line apart, both destructive, one of them final: the whole
             * point of a trash is that the everyday gesture is the reversible one. Skipping it
             * stays possible — from the trash, which is one extra click and a place that says
             * what it is.
             */
            confirm: (selection) => warn(client, t, selection, 'purge'),
            available: (selection, where) => !isEmpty(selection) && where.trashed,
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
    surfaces?: MaybeRefOrGetter<MhActionSurfaces | undefined>,
): UseMediaActionList {
    /*
     * ⚠️ READ INSIDE THE COMPUTED, NOT CAPTURED ONCE. The translator closes over the locale the
     * provider holds, so calling it here is what makes the list follow a language switched at
     * runtime — labels built at setup would stay in whatever language was current at mount.
     */
    const t = useMediaText()

    const all = computed<MhAction[]>(() => {
        const merged = [...defaultActions(client, t, toValue(surfaces) ?? {})]

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

        /* ⚠️ OUTSIDE THE TRASH AND NOT MID-BATCH BY DEFAULT. A caller that says nothing is
         * looking at a library, which is the ordinary case — and the answer has to be one of the
         * two, since "somewhere unspecified" would make every action either always shown or
         * never. */
        const context = toValue(where) ?? { trashed: false, picking: false }

        return all.value.filter((action) => action.available?.(current, context) ?? true)
    })

    return { all, available }
}
