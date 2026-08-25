import { MediaHubError } from './errors'
import type {
    HealthReport,
    AffectedCount,
    SelectionContents,
    BrowsePage,
    BrowseQuery,
    Folder,
    FolderChanges,
    Media,
    MediaChanges,
    Quota,
    Selection,
} from './types'

export interface MediaHubClientOptions {
    /** Where the package's routes are mounted — `/media` unless the host changed the prefix. */
    baseUrl: string

    /**
     * ⚠️ REQUIRED FOR ANYTHING THAT WRITES, and forgetting it produces a 419 nobody connects to
     * this. The package's routes live in the host's `web` group by default, so Laravel expects
     * a CSRF token on every POST, PATCH and DELETE. A function is accepted because the token is
     * rotated on login and a value captured at construction goes stale.
     */
    csrfToken?: string | (() => string | null | undefined)

    /** Injected so the client can be exercised without a network. */
    fetch?: typeof globalThis.fetch

    headers?: Readonly<Record<string, string>>
}

/** What a caller needs to make the browser download an archive itself. */
export interface ArchiveRequest {
    url: string
    fields: Record<string, string[]>
}

export interface MediaHubClient {
    browse(query?: BrowseQuery): Promise<BrowsePage>
    show(id: string): Promise<Media>
    update(id: string, changes: MediaChanges): Promise<Media>
    copy(id: string, folder?: string | null): Promise<Media>

    createFolder(name: string, parent?: string | null): Promise<Folder>
    updateFolder(id: string, changes: FolderChanges): Promise<Folder>

    trash(selection: Selection): Promise<AffectedCount>
    restore(selection: Selection): Promise<AffectedCount>
    purge(selection: Selection): Promise<AffectedCount>

    /**
     * ⚠️ ASKED BEFORE DESTROYING SOMETHING, not after. A folder is never just a folder: the
     * server takes its whole subtree, so this is the only way a confirmation can name what
     * is about to go with it.
     */
    contents(selection: Selection): Promise<SelectionContents>
    emptyTrash(): Promise<AffectedCount>

    quota(): Promise<Quota>

    archiveRequest(selection: Selection, name?: string): ArchiveRequest

    /**
     * The health report, when the host has turned it on.
     *
     * ⚠️ THE ROUTE DOES NOT EXIST WHEN THEY HAVE NOT, so this answers 404 rather than an empty
     * report — and that is the honest shape. "Nothing to report" and "nobody may ask" are
     * different states, and a screen that conflated them would show a clean bill of health for a
     * machine it never looked at.
     */
    diagnostics(): Promise<HealthReport>

    /** The absolute URL of a route, for the rare caller that needs to build its own request. */
    url(path: string): string
    headers(): Record<string, string>
}

/**
 * THE TYPED CLIENT — one method per operation, mirroring the server.
 *
 * ⚠️ THERE IS NO CATCH-ALL METHOD, and that is not an omission. The server exposes one route per
 * operation precisely because a single entry point switching on a field is one authorisation to
 * forget for a dozen behaviours; offering a `perform(action, payload)` here would rebuild that
 * shape on the client and invite a server to grow one.
 */
export function createMediaHubClient(options: MediaHubClientOptions): MediaHubClient {
    const http = options.fetch ?? globalThis.fetch
    const base = options.baseUrl.replace(/\/+$/, '')

    /**
     * ⚠️ A QUERY STRING IS NOT A SEGMENT. Joining it with a slash gives `/media/?page=2`, which
     * most servers tolerate and some rewrite or redirect — and a redirect on a `POST` loses the
     * body. It is appended, never joined.
     */
    function url(path: string): string {
        if (path === '') {
            return base
        }

        return path.startsWith('?') ? `${base}${path}` : `${base}/${path.replace(/^\/+/, '')}`
    }

    function csrf(): string | null {
        const token =
            typeof options.csrfToken === 'function' ? options.csrfToken() : options.csrfToken

        return typeof token === 'string' && token !== '' ? token : null
    }

    /**
     * ⚠️ `Accept: application/json` IS NOT DECORATION. Without it, a validation failure inside
     * the `web` group is answered with a 302 back to the previous page: the client sees a
     * redirect, follows it, parses HTML, and reports something unrelated. With it, the same
     * failure is a 422 carrying the fields.
     */
    function headers(): Record<string, string> {
        const built: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers ?? {}),
        }

        const token = csrf()

        if (token !== null) {
            built['X-CSRF-TOKEN'] = token
        }

        return built
    }

    async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
        const init: RequestInit = { method, headers: headers() }

        if (body !== undefined) {
            init.headers = { ...init.headers, 'Content-Type': 'application/json' }
            init.body = JSON.stringify(body)
        }

        const response = await http(url(path), init)

        if (!response.ok) {
            throw await MediaHubError.fromResponse(response)
        }

        return (await response.json()) as T
    }

    /**
     * ⚠️ AN ABSENT VALUE IS OMITTED, A NULL ONE IS NOT. `folder=` and no `folder` at all mean
     * different things to the server — "move to the root" and "do not move" — and flattening
     * both into an empty string is how a rename silently detaches a file.
     */
    function query(parameters: BrowseQuery): string {
        const search = new URLSearchParams()

        if (parameters.folder !== undefined && parameters.folder !== null) {
            search.set('folder', parameters.folder)
        }

        if (parameters.search !== undefined && parameters.search !== null) {
            search.set('search', parameters.search)
        }

        for (const type of parameters.types ?? []) {
            search.append('types[]', type)
        }

        if (parameters.trashed === true) {
            search.set('trashed', '1')
        }

        if (parameters.sort !== undefined) {
            search.set('sort', parameters.sort)
        }

        if (parameters.direction !== undefined) {
            search.set('direction', parameters.direction)
        }

        if (parameters.page !== undefined) {
            search.set('page', String(parameters.page))
        }

        if (parameters.per_page !== undefined) {
            search.set('per_page', String(parameters.per_page))
        }

        const rendered = search.toString()

        return rendered === '' ? '' : `?${rendered}`
    }

    function selectionBody(selection: Selection): Record<string, string[]> {
        const body: Record<string, string[]> = {}

        if (selection.media !== undefined) {
            body['media'] = [...selection.media]
        }

        if (selection.folders !== undefined) {
            body['folders'] = [...selection.folders]
        }

        return body
    }

    return {
        url,
        headers,

        async browse(parameters: BrowseQuery = {}): Promise<BrowsePage> {
            const answer = await request<{
                data: Omit<BrowsePage, 'meta'>
                meta: BrowsePage['meta']
            }>('GET', query(parameters))

            return { ...answer.data, meta: answer.meta }
        },

        async show(id: string): Promise<Media> {
            return (await request<{ data: Media }>('GET', id)).data
        },

        async update(id: string, changes: MediaChanges): Promise<Media> {
            return (await request<{ data: Media }>('PATCH', id, changes)).data
        },

        async copy(id: string, folder?: string | null): Promise<Media> {
            const body = folder === undefined ? {} : { folder }

            return (await request<{ data: Media }>('POST', `${id}/copy`, body)).data
        },

        async createFolder(name: string, parent?: string | null): Promise<Folder> {
            const body = parent === undefined ? { name } : { name, parent }

            return (await request<{ data: Folder }>('POST', 'folders', body)).data
        },

        async updateFolder(id: string, changes: FolderChanges): Promise<Folder> {
            return (await request<{ data: Folder }>('PATCH', `folders/${id}`, changes)).data
        },

        async trash(selection: Selection): Promise<AffectedCount> {
            return (await request<{ data: AffectedCount }>('POST', 'trash', selectionBody(selection))).data
        },

        async restore(selection: Selection): Promise<AffectedCount> {
            return (await request<{ data: AffectedCount }>('POST', 'trash/restore', selectionBody(selection))).data
        },

        async purge(selection: Selection): Promise<AffectedCount> {
            return (await request<{ data: AffectedCount }>('POST', 'trash/purge', selectionBody(selection))).data
        },

        async contents(selection: Selection): Promise<SelectionContents> {
            return (await request<{ data: SelectionContents }>('POST', 'contents', selectionBody(selection))).data
        },

        async emptyTrash(): Promise<AffectedCount> {
            return (await request<{ data: AffectedCount }>('DELETE', 'trash')).data
        },

        async quota(): Promise<Quota> {
            return (await request<{ data: Quota }>('GET', 'quota')).data
        },

        async diagnostics(): Promise<HealthReport> {
            return (await request<{ data: HealthReport }>('GET', 'diagnostics')).data
        },

        /**
         * ⚠️ THERE IS NO `archive(): Promise<Blob>`, AND THAT IS DELIBERATE. Fetching the archive
         * and turning it into a blob puts the WHOLE thing in the tab's memory — which is exactly
         * what streaming it was built to avoid, and it fails on the archives that most need to
         * work. What is returned here is what a hidden form needs to let the browser save the
         * response natively, one chunk at a time.
         */
        archiveRequest(selection: Selection, name?: string): ArchiveRequest {
            const fields = selectionBody(selection)

            if (name !== undefined) {
                fields['name'] = [name]
            }

            return { url: url('archive'), fields }
        },
    }
}
