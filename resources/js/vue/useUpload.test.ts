import { effectScope, nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import type { UploadTransport, UploadTransportRequest, UploadTransportResponse } from '../client'
import { fakeClient } from './fake.test-utils'
import { useUpload } from './useUpload'

function file(name = 'photo.png'): File {
    return new File(['some bytes'], name, { type: 'image/png' })
}

function controllable() {
    const seen: UploadTransportRequest[] = []
    const pending: Array<(response: UploadTransportResponse) => void> = []

    const transport: UploadTransport = (request) => {
        seen.push(request)

        return new Promise<UploadTransportResponse>((resolve) => {
            pending.push(resolve)
        })
    }

    return {
        transport,
        seen,
        settle(index: number, body: unknown): void {
            pending[index]?.({ status: 201, body })
        },
    }
}

async function settleAll(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

/** Composables that release resources on teardown need a scope to be released from. */
function inScope<T>(build: () => T): { value: T; dispose: () => void } {
    const scope = effectScope()
    const value = scope.run(build) as T

    return { value, dispose: () => scope.stop() }
}

describe('the upload queue as state', () => {
    it('exposes what was enqueued', async () => {
        const bus = controllable()
        const { value: upload } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport, concurrency: 5 }),
        )

        upload.add([file('a.png'), file('b.png')])
        await settleAll()

        expect(upload.items.value).toHaveLength(2)
        expect(upload.uploading.value).toBe(true)
    })

    /**
     * ⚠️ THE QUEUE MUTATES ITS ITEMS IN PLACE — that is what makes dozens of progress events per
     * file cheap. A shallow ref therefore has to be nudged by hand; without that, the bar never
     * moves and nothing in the console says why.
     */
    it('notices progress on an item it already handed out', async () => {
        const bus = controllable()
        const { value: upload } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport }),
        )

        upload.add([file()])
        await settleAll()

        bus.seen[0]?.onProgress(250, 1000)
        await settleAll()

        expect(upload.progress.value).toBe(0.25)
    })

    it('averages progress over what is still running', async () => {
        const bus = controllable()
        const { value: upload } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport, concurrency: 2 }),
        )

        upload.add([file('a.png'), file('b.png')])
        await settleAll()

        bus.seen[0]?.onProgress(1000, 1000)
        bus.seen[1]?.onProgress(0, 1000)
        await settleAll()

        expect(upload.progress.value).toBe(0.5)
    })

    /** ⚠️ AN EMPTY QUEUE IS NOT A FINISHED ONE — a bar at 100 % before anything was added lies. */
    it('reports nothing rather than everything when it is empty', () => {
        const { value: upload } = inScope(() => useUpload(fakeClient(), { transport: controllable().transport }))

        expect(upload.progress.value).toBe(0)
        expect(upload.uploading.value).toBe(false)
    })

    it('collects what was stored and what failed', async () => {
        const bus = controllable()
        const { value: upload } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport, concurrency: 2 }),
        )

        upload.add([file('a.png'), file('b.png')])
        await settleAll()

        bus.settle(0, { data: [{ id: 'm1' }], errors: [] })
        bus.settle(1, { data: [], errors: [{ file: 'b.png', reason: 'too_large' }] })
        await settleAll()

        expect(upload.stored.value.map((item) => item.id)).toEqual(['m1'])
        expect(upload.failed.value).toHaveLength(1)
        expect(upload.failed.value[0]?.reason).toBe('too_large')
    })

    it('forwards abort, retry and clearing to the queue', async () => {
        const bus = controllable()
        const { value: upload } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport, concurrency: 1 }),
        )

        const items = upload.add([file('a.png'), file('b.png')])
        await settleAll()

        upload.abort(items[1]?.id ?? '')
        await settleAll()

        expect(upload.items.value[1]?.status).toBe('aborted')

        upload.clearFinished()
        await settleAll()

        expect(upload.items.value).toHaveLength(1)
    })

    /**
     * ⚠️ A LISTENER LEFT BEHIND KEEPS THE COMPONENT ALIVE THROUGH ITS CLOSURE. On a screen opened
     * and closed all day, that is a leak nobody attributes to the media library.
     */
    it('lets go of the queue when its scope ends', async () => {
        const bus = controllable()
        const { value: upload, dispose } = inScope(() =>
            useUpload(fakeClient(), { transport: bus.transport }),
        )

        upload.add([file()])
        await settleAll()

        const before = upload.items.value

        dispose()

        upload.queue.enqueue([file('after.png')])
        await settleAll()

        expect(upload.items.value).toBe(before)
        expect(upload.queue.items).toHaveLength(2)
    })
})
