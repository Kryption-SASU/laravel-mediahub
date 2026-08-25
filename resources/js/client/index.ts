/*
 * LAYER 1 — THE CORE, WITHOUT VUE.
 *
 * ⚠️ NOTHING UNDER THIS FOLDER MAY IMPORT `vue`, and it is not a matter of taste. An Angular
 * application has to be able to consume the typed client and the upload queue without pulling a
 * second framework into its bundle. A test in `no-vue.test.ts` reads the sources and refuses the
 * import, because a convention nobody checks is a convention that lasts until the first hurry.
 */

export { createMediaHubClient } from './client'
export type { ArchiveRequest, MediaHubClient, MediaHubClientOptions } from './client'

export { MediaHubError } from './errors'

export { createUploadQueue, xhrTransport } from './uploads'
export type {
    UploadItem,
    UploadQueue,
    UploadQueueOptions,
    UploadStatus,
    UploadTransport,
    UploadTransportRequest,
    UploadTransportResponse,
} from './uploads'

/** ⚠️ A VALUE, NOT A TYPE — see the note on the declaration: the union has to survive the build. */
export { MEDIA_TYPES } from './types'

export type {
    AffectedCount,
    SelectionContents,
    BrowsePage,
    BrowseQuery,
    Folder,
    FolderChanges,
    Media,
    MediaChanges,
    MediaSort,
    MediaType,
    PageMeta,
    Quota,
    Selection,
    UploadRejection,
    UploadResult,
} from './types'
