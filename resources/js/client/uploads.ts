import type { MediaHubClient } from './client'
import type { Media, UploadRejection } from './types'

export type UploadStatus = 'pending' | 'uploading' | 'done' | 'failed' | 'aborted'

export interface UploadItem {
    readonly id: string
    readonly file: File
    readonly folder: string | null
    status: UploadStatus
    /** Between 0 and 1. Stays at 0 when the transport cannot report progress. */
    progress: number
    media: Media | null
    /** The server's stable key when it refused, `null` otherwise. */
    reason: string | null
    message: string | null
}

export interface UploadTransportRequest {
    url: string
    headers: Record<string, string>
    body: FormData
    signal: AbortSignal
    onProgress: (loaded: number, total: number) => void
}

export interface UploadTransportResponse {
    status: number
    body: unknown
}

export type UploadTransport = (request: UploadTransportRequest) => Promise<UploadTransportResponse>

export interface UploadQueueOptions {
    /**
     * ⚠️ THREE AT A TIME, NOT ALL OF THEM. A person dropping forty photographs on a domestic
     * connection saturates it: every upload crawls, the browser caps the connections anyway, and
     * the first file finishes last. A small window means the first ones land quickly and the
     * screen has something to show.
     */
    concurrency?: number
    transport?: UploadTransport
}

export interface UploadQueue {
    readonly items: readonly UploadItem[]
    readonly idle: boolean
    enqueue(files: Iterable<File>, options?: { folder?: string | null }): UploadItem[]
    abort(id: string): void
    retry(id: string): void
    clearFinished(): void
    /** Called after every state change. Returns the unsubscribe. */
    subscribe(listener: () => void): () => void
}

/**
 * THE DEFAULT TRANSPORT IS `XMLHttpRequest`, AND THAT IS NOT NOSTALGIA.
 *
 * ⚠️ `fetch()` CANNOT REPORT UPLOAD PROGRESS. It reports download progress through a readable
 * body, and nothing at all for what goes up: there is no event, no stream, no callback. A queue
 * built on it shows a spinner and no percentage — on exactly the files where a percentage is the
 * only thing making the wait bearable.
 */
export function xhrTransport(request: UploadTransportRequest): Promise<UploadTransportResponse> {
    if (typeof XMLHttpRequest === 'undefined') {
        return Promise.reject(
            new Error('No XMLHttpRequest here. Pass a transport of your own to createUploadQueue().'),
        )
    }

    return new Promise<UploadTransportResponse>((resolve, reject) => {
        const xhr = new XMLHttpRequest()

        xhr.open('POST', request.url, true)

        for (const [name, value] of Object.entries(request.headers)) {
            xhr.setRequestHeader(name, value)
        }

        xhr.upload.addEventListener('progress', (event) => {
            request.onProgress(event.loaded, event.lengthComputable ? event.total : 0)
        })

        xhr.addEventListener('load', () => {
            let body: unknown = null

            try {
                body = JSON.parse(xhr.responseText) as unknown
            } catch {
                body = null
            }

            resolve({ status: xhr.status, body })
        })

        xhr.addEventListener('error', () => reject(new Error('The upload could not reach the server.')))
        xhr.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))

        request.signal.addEventListener('abort', () => xhr.abort())

        xhr.send(request.body)
    })
}

/**
 * A QUEUE OF UPLOADS — one request per file.
 *
 * ⚠️ ONE FILE PER REQUEST, THOUGH THE SERVER ACCEPTS SEVERAL. Sending twenty photographs in one
 * request gives one progress bar for the lot, one thing to abort, and an all-or-nothing retry:
 * the nineteenth failing makes the person send the other nineteen again. Per file, each one
 * reports, aborts and retries on its own. The cost is more requests, and it is worth it.
 *
 * ⚠️ AND THERE IS NO CHUNKING, because no route accepts one. Saying otherwise would be inventing
 * an endpoint; a large file is a single request until the server offers something else.
 */
export function createUploadQueue(client: MediaHubClient, options: UploadQueueOptions = {}): UploadQueue {
    const concurrency = Math.max(1, options.concurrency ?? 3)
    const transport = options.transport ?? xhrTransport

    const items: UploadItem[] = []
    const listeners = new Set<() => void>()
    const controllers = new Map<string, AbortController>()

    let sequence = 0
    let active = 0

    function changed(): void {
        for (const listener of listeners) {
            listener()
        }
    }

    function pump(): void {
        while (active < concurrency) {
            const next = items.find((item) => item.status === 'pending')

            if (next === undefined) {
                return
            }

            active++
            void send(next).finally(() => {
                active--
                pump()
            })
        }
    }

    async function send(item: UploadItem): Promise<void> {
        item.status = 'uploading'
        item.progress = 0
        changed()

        const body = new FormData()
        body.append('files[]', item.file, item.file.name)

        if (item.folder !== null) {
            body.append('folder', item.folder)
        }

        const controller = new AbortController()
        controllers.set(item.id, controller)

        try {
            const response = await transport({
                url: client.url(''),
                headers: client.headers(),
                body,
                signal: controller.signal,
                onProgress: (loaded, total) => {
                    item.progress = total > 0 ? Math.min(1, loaded / total) : 0
                    changed()
                },
            })

            settle(item, response)
        } catch (failure) {
            item.status = isAbort(failure) ? 'aborted' : 'failed'
            item.reason = null
            item.message = failure instanceof Error ? failure.message : 'The upload failed.'
        } finally {
            controllers.delete(item.id)
            changed()
        }
    }

    /**
     * ⚠️ A 201 IS NOT ENOUGH TO CALL IT DONE. The upload route answers per file: a request can
     * succeed while the only file in it was refused, and the body says which. Reading the status
     * alone reports a success for something that was never stored.
     */
    function settle(item: UploadItem, response: UploadTransportResponse): void {
        const body = (response.body ?? {}) as { data?: Media[]; errors?: UploadRejection[] }

        const stored = Array.isArray(body.data) ? body.data[0] : undefined
        const refused = Array.isArray(body.errors) ? body.errors[0] : undefined

        if (stored !== undefined) {
            item.status = 'done'
            item.progress = 1
            item.media = stored
            item.reason = null
            item.message = null

            return
        }

        item.status = 'failed'
        item.reason = refused?.reason ?? null
        item.message =
            refused === undefined ? `The server answered ${response.status}.` : refused.reason
    }

    function isAbort(failure: unknown): boolean {
        return failure instanceof Error && failure.name === 'AbortError'
    }

    return {
        get items(): readonly UploadItem[] {
            return items
        },

        get idle(): boolean {
            return items.every((item) => item.status !== 'pending' && item.status !== 'uploading')
        },

        enqueue(files: Iterable<File>, enqueueOptions: { folder?: string | null } = {}): UploadItem[] {
            const added: UploadItem[] = []

            for (const file of files) {
                const item: UploadItem = {
                    id: `upload-${++sequence}`,
                    file,
                    folder: enqueueOptions.folder ?? null,
                    status: 'pending',
                    progress: 0,
                    media: null,
                    reason: null,
                    message: null,
                }

                items.push(item)
                added.push(item)
            }

            changed()
            pump()

            return added
        },

        /**
         * ⚠️ ABORTING SOMETHING STILL WAITING MUST WORK TOO. A person who drops forty files and
         * changes their mind cancels the lot; only three are in flight, and the other
         * thirty-seven have no request to abort.
         */
        abort(id: string): void {
            const item = items.find((candidate) => candidate.id === id)

            if (item === undefined) {
                return
            }

            if (item.status === 'pending') {
                item.status = 'aborted'
                changed()

                return
            }

            controllers.get(id)?.abort()
        },

        retry(id: string): void {
            const item = items.find((candidate) => candidate.id === id)

            if (item === undefined || item.status === 'uploading' || item.status === 'done') {
                return
            }

            item.status = 'pending'
            item.progress = 0
            item.reason = null
            item.message = null

            changed()
            pump()
        },

        clearFinished(): void {
            for (let index = items.length - 1; index >= 0; index--) {
                const status = items[index]?.status

                if (status === 'done' || status === 'failed' || status === 'aborted') {
                    items.splice(index, 1)
                }
            }

            changed()
        },

        subscribe(listener: () => void): () => void {
            listeners.add(listener)

            return () => {
                listeners.delete(listener)
            }
        },
    }
}
