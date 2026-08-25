/*
 * THE SHAPES THE SERVER ACTUALLY RETURNS.
 *
 * ⚠️ THESE TYPES ARE A CLAIM ABOUT ANOTHER PROGRAM, and a claim nobody checks is worse than no
 * claim: it reads as a guarantee. They are therefore confronted, in `contract.test.ts`, with
 * fixtures written by the PHP suite from real responses. If a resource changes a key, the PHP
 * side goes red first and this side follows.
 *
 * ⚠️ AND NOTHING HERE IS OPTIONAL BECAUSE IT "MIGHT" BE ABSENT. A key the server always sends —
 * `folder_id`, even at the root — is declared present and nullable. Marking it optional would
 * let a client stop telling "at the root" from "the server did not say", which is precisely the
 * distinction the resource was written to preserve.
 */

/**
 * What the library calls a file's nature. Derived from the MIME type, never typed in.
 *
 * ⚠️ THE LIST EXISTS AT RUNTIME, NOT ONLY IN THE TYPES. A union is erased at build time: it
 * tells the compiler what the server sends, and can do nothing at all when the server sends
 * something else. Should a seventh kind ever be added on the other side, a program holding only
 * the union would carry an impossible value while every type still checks out — and the failure
 * would surface as a missing icon on a screen, far from the cause. Keeping the values means the
 * belief can be confronted with a real payload, which is what `contract.test.ts` does.
 */
export const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'external', 'other'] as const

export type MediaType = (typeof MEDIA_TYPES)[number]

export interface Media {
    /**
     * ⚠️ THE ROUTE KEY, NOT THE DATABASE KEY. A `uuid` on a standalone schema, an `id` on an
     * adopted one — a string in both cases, and never something to do arithmetic on.
     */
    id: string
    name: string
    file_name: string | null
    extension: string | null
    mime_type: string
    type: MediaType
    size: number
    width: number | null
    height: number | null
    duration: number | null
    /** `null` means the root. The key is always present — see the note above. */
    folder_id: string | null
    custom_properties: Record<string, unknown>
    url: string
    download_url: string
    /** `null` while a derivative is being built, and for everything that has none. */
    thumbnail_url: string | null
    trashed_at: string | null
    created_at: string | null
    updated_at: string | null
}

export interface Folder {
    id: string
    name: string
    slug: string
    /** A display path built from the branch's slugs. ⚠️ It names nothing on the storage. */
    path: string
    depth: number
    parent_id: string | null
    trashed_at: string | null
    created_at: string | null
    updated_at: string | null
}

export interface PageMeta {
    current_page: number
    last_page: number
    per_page: number
    total: number
}

export interface BrowsePage {
    /** The folder being looked at, or `null` at the root. */
    folder: Folder | null
    breadcrumbs: Folder[]
    folders: Folder[]
    media: Media[]
    meta: PageMeta
}

/**
 * ⚠️ `sort` IS A CLOSED SET, AND THAT MIRRORS THE SERVER'S ALLOW-LIST. Sorting on anything else
 * is silently ignored there; typing it as a free string here would invite a caller to send a
 * column name and wonder why the order never changes.
 */
export type MediaSort = 'name' | 'created_at' | 'updated_at' | 'size'

export interface BrowseQuery {
    folder?: string | null
    search?: string | null
    types?: readonly MediaType[]
    trashed?: boolean
    sort?: MediaSort
    direction?: 'asc' | 'desc'
    page?: number
    per_page?: number
}

/**
 * ⚠️ TWO LISTS, NOT ONE WITH A FLAG. It is the server's contract, and for the reason written
 * there: a flag supplied by the caller decides which table is searched, and therefore which
 * check applies.
 */
export interface Selection {
    media?: readonly string[]
    folders?: readonly string[]
}

export interface Quota {
    /** `null` means unlimited — never zero, which would read as "full". */
    limit: number | null
    used: number
    remaining: number | null
    unlimited: boolean
}

export interface MediaChanges {
    name?: string
    properties?: Record<string, unknown>
    /**
     * ⚠️ PRESENT AND `null` MOVES TO THE ROOT; ABSENT MOVES NOTHING. The distinction is the
     * server's, and losing it here would make every rename detach the file from its folder.
     */
    folder?: string | null
}

export interface FolderChanges {
    name?: string
    /** Same rule as `MediaChanges.folder`: absent means "do not move". */
    parent?: string | null
}

/** What a batch operation answers. */
/**
 * WHAT A SELECTION CARRIES, all the way down.
 *
 * ⚠️ THESE ARE THE NUMBERS THE ACTION WILL TOUCH, not the ones somebody ticked. Trashing a folder
 * takes its whole subtree — that is the right behaviour, and it means "1 folder" can mean four
 * hundred files. A confirmation built on the ticked count would reassure somebody about a figure
 * the operation never uses.
 */
export interface SelectionContents {
    media: number
    folders: number
}

export interface AffectedCount {
    count: number
}

/** One file the server refused, from a multi-file upload. */
export interface UploadRejection {
    file: string
    reason: string
}

export interface UploadResult {
    data: Media[]
    errors: UploadRejection[]
}
