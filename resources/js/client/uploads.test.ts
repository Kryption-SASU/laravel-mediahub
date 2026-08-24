import { describe, expect, it } from 'vitest'
import { createMediaHubClient } from './client'
import { createUploadQueue } from './uploads'
import type { UploadTransport, UploadTransportRequest, UploadTransportResponse } from './uploads'

const api = createMediaHubClient({
    baseUrl: '/media',
    fetch: (() => Promise.reject(new Error('the queue must not use fetch'))) as typeof globalThis.fetch,
})

function file(name = 'photo.png'): File {
    return new File(['some bytes'], name, { type: 'image/png' })
}

function stored(id: string): unknown {
    return { data: [{ id, name: id }], errors: [] }
}

/**
 * A TRANSPORT WHOSE ANSWERS ARE RELEASED BY HAND.
 *
 * ⚠️ WITHOUT THAT CONTROL, CONCURRENCY CANNOT BE OBSERVED. A transport that resolves immediately
 * lets every upload finish before the next is even started: the queue would pass a concurrency
 * test while running everything one at a time, or all at once, indistinguishably.
 */
function controllable() {
    const seen: UploadTransportRequest[] = []
    const pending: Array<(response: UploadTransportResponse) => void> = []
    const rejects: Array<(failure: unknown) => void> = []

    const transport: UploadTransport = (request) => {
        seen.push(request)

        return new Promise<UploadTransportResponse>((resolve, reject) => {
            pending.push(resolve)
            rejects.push(reject)

            request.signal.addEventListener('abort', () => {
                reject(new DOMException('Aborted', 'AbortError'))
            })
        })
    }

    return {
        transport,
        seen,
        settle(index: number, body: unknown, status = 201): Promise<void> {
            pending[index]?.({ status, body })

            return Promise.resolve()
        },
        fail(index: number, failure: unknown): void {
            rejects[index]?.(failure)
        },
    }
}

/** Lets the microtask queue drain, so a settled promise reaches its `finally`. */
async function settleAll(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }
}

describe('the queue', () => {
    /** ⚠️ ONE REQUEST PER FILE — per-file progress, abort and retry all depend on it. */
    it('sends one request per file', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport, concurrency: 5 })

        queue.enqueue([file('a.png'), file('b.png')])
        await settleAll()

        expect(bus.seen).toHaveLength(2)
    })

    it('runs no more than the concurrency at once', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport, concurrency: 2 })

        queue.enqueue([file('a.png'), file('b.png'), file('c.png')])
        await settleAll()

        expect(bus.seen).toHaveLength(2)

        await bus.settle(0, stored('m1'))
        await settleAll()

        expect(bus.seen).toHaveLength(3)
    })

    it('reports progress as a ratio', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()

        bus.seen[0]?.onProgress(512, 1024)

        expect(item?.progress).toBe(0.5)
    })

    /** ⚠️ A TRANSPORT THAT CANNOT MEASURE MUST NOT INVENT A PERCENTAGE. */
    it('stays at zero when the total is unknown', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()

        bus.seen[0]?.onProgress(512, 0)

        expect(item?.progress).toBe(0)
    })

    it('sends the folder when there is one, and not otherwise', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport, concurrency: 5 })

        queue.enqueue([file('a.png')], { folder: 'f1' })
        queue.enqueue([file('b.png')])
        await settleAll()

        expect(bus.seen[0]?.body.get('folder')).toBe('f1')
        expect(bus.seen[1]?.body.get('folder')).toBeNull()
    })
})

describe('what the server answered', () => {
    it('keeps the media when the file was stored', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()
        await bus.settle(0, stored('m1'))
        await settleAll()

        expect(item?.status).toBe('done')
        expect(item?.progress).toBe(1)
        expect(item?.media?.id).toBe('m1')
    })

    /**
     * ⚠️ A 201 IS NOT ENOUGH TO CALL IT DONE. The route answers per file: the request succeeds
     * while the only file in it was refused, and the body says which. Reading the status alone
     * reports a success for something that was never stored.
     */
    it('fails on a refusal even when the status is a success', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file('script.sh')])
        await settleAll()
        await bus.settle(0, { data: [], errors: [{ file: 'script.sh', reason: 'extension_not_allowed' }] }, 201)
        await settleAll()

        expect(item?.status).toBe('failed')
        expect(item?.reason).toBe('extension_not_allowed')
    })

    it('reports the status when the body says nothing', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()
        await bus.settle(0, {}, 500)
        await settleAll()

        expect(item?.status).toBe('failed')
        expect(item?.reason).toBeNull()
        expect(item?.message).toContain('500')
    })

    it('marks a transport failure as failed, not aborted', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()
        bus.fail(0, new Error('the network went away'))
        await settleAll()

        expect(item?.status).toBe('failed')
        expect(item?.message).toBe('the network went away')
    })
})

describe('changing one’s mind', () => {
    it('aborts something in flight', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()

        queue.abort(item?.id ?? '')
        await settleAll()

        expect(item?.status).toBe('aborted')
    })

    /**
     * ⚠️ AND SOMETHING STILL WAITING HAS NO REQUEST TO ABORT. A person who drops forty files and
     * changes their mind cancels the lot; only three are in flight.
     */
    it('aborts something that has not started', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport, concurrency: 1 })

        const items = queue.enqueue([file('a.png'), file('b.png')])
        await settleAll()

        queue.abort(items[1]?.id ?? '')

        expect(items[1]?.status).toBe('aborted')
        expect(bus.seen).toHaveLength(1)
    })

    it('retries what failed', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()
        bus.fail(0, new Error('nope'))
        await settleAll()

        queue.retry(item?.id ?? '')
        await settleAll()
        await bus.settle(1, stored('m1'))
        await settleAll()

        expect(item?.status).toBe('done')
        expect(bus.seen).toHaveLength(2)
    })

    /** ⚠️ RETRYING SOMETHING ALREADY STORED WOULD UPLOAD IT A SECOND TIME. */
    it('refuses to retry what succeeded', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        const [item] = queue.enqueue([file()])
        await settleAll()
        await bus.settle(0, stored('m1'))
        await settleAll()

        queue.retry(item?.id ?? '')
        await settleAll()

        expect(bus.seen).toHaveLength(1)
    })

    it('clears what is finished and keeps what is not', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport, concurrency: 1 })

        queue.enqueue([file('a.png'), file('b.png')])
        await settleAll()
        await bus.settle(0, stored('m1'))
        await settleAll()

        queue.clearFinished()

        expect(queue.items).toHaveLength(1)
    })
})

describe('watching it', () => {
    it('tells its listeners and lets them leave', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        let calls = 0
        const stop = queue.subscribe(() => {
            calls++
        })

        queue.enqueue([file()])
        await settleAll()

        const seen = calls
        expect(seen).toBeGreaterThan(0)

        stop()
        queue.enqueue([file()])
        await settleAll()

        expect(calls).toBe(seen)
    })

    it('knows when there is nothing left to do', async () => {
        const bus = controllable()
        const queue = createUploadQueue(api, { transport: bus.transport })

        expect(queue.idle).toBe(true)

        queue.enqueue([file()])
        await settleAll()

        expect(queue.idle).toBe(false)

        await bus.settle(0, stored('m1'))
        await settleAll()

        expect(queue.idle).toBe(true)
    })
})

describe('the default transport', () => {
    /** ⚠️ NO `XMLHttpRequest` HERE, and the message has to say what to do about it. */
    it('says what is missing rather than failing obscurely', async () => {
        const queue = createUploadQueue(api)

        const [item] = queue.enqueue([file()])
        await settleAll()

        expect(item?.status).toBe('failed')
        expect(item?.message).toContain('transport')
    })
})
