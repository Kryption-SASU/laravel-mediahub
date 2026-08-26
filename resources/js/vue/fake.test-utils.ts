import type { ArchiveProgress, BrowsePage, Folder, HealthReport, Media, MediaHubClient, Quota } from '../client'
import { MediaHubError } from '../client'

/**
 * A CLIENT THAT ANSWERS WITHOUT A SERVER.
 *
 * ⚠️ IT RECORDS WHAT IT WAS ASKED, and that matters more than what it returns. Most of what the
 * composables have to get right is the shape of the REQUEST — a `folder: null` that must travel,
 * a `parent` that must not — and a fake that only returned canned data would let all of it
 * through.
 */
export interface FakeClient extends MediaHubClient {
    readonly calls: Array<{ method: string; args: unknown[] }>
    answerBrowse(page: Partial<BrowsePage>): void
    answerQuota(quota: Quota): void
    failWith(error: MediaHubError | null): void
    answerContents(contents: { media: number; folders: number }): void
    answerHealth(report: HealthReport): void
    /** What the server would say about an archive in flight. Null = never heard of it. */
    answerProgress(seen: ArchiveProgress | null): void
}

export function media(id: string, over: Partial<Media> = {}): Media {
    return {
        id,
        name: id,
        file_name: `${id}.pdf`,
        extension: 'pdf',
        mime_type: 'application/pdf',
        type: 'document',
        size: 1024,
        width: null,
        height: null,
        duration: null,
        folder_id: null,
        custom_properties: {},
        url: `/media/${id}/file`,
        download_url: `/media/${id}/download`,
        thumbnail_url: null,
        can_draw: false,
        trashed_at: null,
        created_at: null,
        updated_at: null,
        ...over,
    }
}

export function folder(id: string, over: Partial<Folder> = {}): Folder {
    return {
        id,
        name: id,
        slug: id,
        path: id,
        depth: 0,
        parent_id: null,
        trashed_at: null,
        created_at: null,
        updated_at: null,
        ...over,
    }
}

const emptyPage: BrowsePage = {
    folder: null,
    breadcrumbs: [],
    folders: [],
    media: [],
    meta: { current_page: 1, last_page: 1, per_page: 24, total: 0 },
}

export function fakeClient(): FakeClient {
    const calls: Array<{ method: string; args: unknown[] }> = []

    let page: BrowsePage = emptyPage
    let quota: Quota = { limit: null, used: 0, remaining: null, unlimited: true }
    let failure: MediaHubError | null = null
    let contents = { media: 0, folders: 0 }

    /* ⚠️ A CLEAN BILL BY DEFAULT, so a bench that never mentions the report is not quietly
     * asserting one. What is being reported is the screen's behaviour, not this machine's. */
    let health: HealthReport = { ok: true, checks: [] }

    /* ⚠️ NOTHING KNOWN BY DEFAULT. A fixture that answered "known" unasked would settle
     * every archive on its first question, and the wait would never be exercised. */
    let progress: ArchiveProgress | null = null

    function record<T>(method: string, args: unknown[], value: T): Promise<T> {
        calls.push({ method, args })

        return failure === null ? Promise.resolve(value) : Promise.reject(failure)
    }

    return {
        calls,

        answerBrowse(partial: Partial<BrowsePage>): void {
            page = { ...emptyPage, ...partial }
        },

        answerQuota(next: Quota): void {
            quota = next
        },

        failWith(error: MediaHubError | null): void {
            failure = error
        },

        answerProgress(seen: ArchiveProgress | null): void {
            progress = seen
        },

        /* ⚠️ WHAT A SELECTION CARRIES IS THE SERVER'S ANSWER, so a bench that wants to exercise a
         * confirmation naming four hundred files says so here rather than counting anything. */
        answerContents(next: { media: number; folders: number }): void {
            contents = next
        },

        answerHealth(report: HealthReport): void {
            health = report
        },

        url: (path: string) => (path === '' ? '/media' : `/media/${path}`),
        headers: () => ({ Accept: 'application/json' }),

        browse: (query) => record('browse', [query], page),
        show: (id) => record('show', [id], media(id)),
        update: (id, changes) => record('update', [id, changes], media(id)),
        copy: (id, target) => record('copy', [id, target], media(`${id}-copy`)),
        regenerate: (id) => record('regenerate', [id], media(id)),
        createFolder: (name, parent) => record('createFolder', [name, parent], folder(name)),
        updateFolder: (id, changes) => record('updateFolder', [id, changes], folder(id)),
        trash: (selection) => record('trash', [selection], { count: 1 }),
        restore: (selection) => record('restore', [selection], { count: 1 }),
        purge: (selection) => record('purge', [selection], { count: 1 }),
        contents: (selection) => record('contents', [selection], contents),
        emptyTrash: () => record('emptyTrash', [], { count: 2 }),
        quota: () => record('quota', [], quota),
        diagnostics: () => record('diagnostics', [], health),

        /**
         * ⚠️ IT BUILDS THE FIELDS THE REAL CLIENT BUILDS, and returning an empty set was a trap.
         * A bench checking that a selection reaches the server as a list passed on a form with no
         * inputs at all: the fixture asserted nothing, quietly, and would have gone on doing so
         * while the real thing sent one file out of five.
         */
        archiveRequest: (selection, name) => {
            calls.push({ method: 'archiveRequest', args: [selection, name] })

            const fields: Record<string, string[]> = {}

            /* ⚠️ INCLUDING THE BRACKETS, which is where the real one puts them: a fixture that
             * left them off would let a form writing `field + '[]'` pass, and that form is
             * exactly what was wrong. */
            if (selection.media !== undefined) {
                fields['media[]'] = [...selection.media]
            }

            if (selection.folders !== undefined) {
                fields['folders[]'] = [...selection.folders]
            }

            if (name !== undefined) {
                fields['name'] = [name]
            }

            return { url: '/media/archive', fields }
        },

        /**
         * ⚠️ "NEVER HEARD OF IT" BY DEFAULT, WHICH IS THE HONEST STARTING STATE. A bench that
         * said "known" without being told to would have every archive settle on its first
         * question, and the wait this fixture exists to exercise would never happen. Tests that
         * want a count set one with `answerProgress`.
         */
        archiveProgress: async (ticket) => {
            calls.push({ method: 'archiveProgress', args: [ticket] })

            return progress ?? { known: false, total: 0, written: 0, done: false }
        },
    }
}

/**
 * A PROMISE WHOSE RESOLVER IS AVAILABLE BEFORE IT SETTLES — for tests that need to hold one
 * answer while another overtakes it.
 *
 * ⚠️ THE DEFINITE ASSIGNMENT IS NOT A SHORTCUT. Keeping the resolver in a mutable variable
 * instead leaves the compiler narrowing it to null at the call site, and it is right to: it
 * cannot know the executor already ran. The executor of a Promise does run synchronously, which
 * is exactly what this asserts and nothing else.
 */
export function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void

    const promise = new Promise<T>((settle) => {
        resolve = settle
    })

    return { promise, resolve }
}
