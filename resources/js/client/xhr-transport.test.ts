import { afterEach, describe, expect, it } from 'vitest'
import type { UploadTransportRequest } from './uploads'
import { xhrTransport } from './uploads'

/**
 * THE TRANSPORT THAT EXISTS ONLY TO REPORT PROGRESS.
 *
 * ⚠️ THIS IS THE WHOLE REASON THE QUEUE DOES NOT USE `fetch`, and it was the least covered code
 * of the layer. `fetch` reports nothing at all about what goes up: a queue built on it shows a
 * spinner and no percentage, on exactly the files where a percentage is what makes the wait
 * bearable. Leaving it untested meant the one thing this function is for was believed rather
 * than checked.
 *
 * ⚠️ THE FAKE IS A REAL FAKE, NOT A MOCK OF THE FUNCTION UNDER TEST. It stands in for the
 * browser object and records what was asked of it, so what is verified is the conversation with
 * `XMLHttpRequest` — the part a Node test cannot otherwise see.
 */

interface Listener {
    (event: { loaded: number; total: number; lengthComputable: boolean }): void
}

class FakeXhr {
    static last: FakeXhr | null = null

    status = 200

    responseText = '{}'

    aborted = false

    sent: unknown = null

    readonly headers: Record<string, string> = {}

    opened: [string, string, boolean] | null = null

    private readonly listeners = new Map<string, Listener[]>()

    readonly upload = {
        addEventListener: (name: string, listener: Listener): void => {
            this.on(`upload:${name}`, listener)
        },
    }

    constructor() {
        FakeXhr.last = this
    }

    open(method: string, url: string, async: boolean): void {
        this.opened = [method, url, async]
    }

    setRequestHeader(name: string, value: string): void {
        this.headers[name] = value
    }

    addEventListener(name: string, listener: Listener): void {
        this.on(name, listener)
    }

    send(body: unknown): void {
        this.sent = body
    }

    abort(): void {
        this.aborted = true
        this.fire('abort')
    }

    fire(name: string, event: Partial<{ loaded: number; total: number; lengthComputable: boolean }> = {}): void {
        for (const listener of this.listeners.get(name) ?? []) {
            listener({ loaded: 0, total: 0, lengthComputable: true, ...event })
        }
    }

    private on(name: string, listener: Listener): void {
        const existing = this.listeners.get(name) ?? []

        existing.push(listener)
        this.listeners.set(name, existing)
    }
}

function install(): void {
    ;(globalThis as { XMLHttpRequest?: unknown }).XMLHttpRequest = FakeXhr
}

function uninstall(): void {
    delete (globalThis as { XMLHttpRequest?: unknown }).XMLHttpRequest
}

function request(over: Partial<UploadTransportRequest> = {}): UploadTransportRequest {
    return {
        url: '/media',
        headers: { Accept: 'application/json' },
        body: new FormData(),
        signal: new AbortController().signal,
        onProgress: () => undefined,
        ...over,
    }
}

afterEach(() => {
    uninstall()
    FakeXhr.last = null
})

describe('the upload transport', () => {
    it('posts to the address it was given, with its headers', async () => {
        install()

        const pending = xhrTransport(request({ headers: { 'X-CSRF-TOKEN': 'abc' } }))

        FakeXhr.last?.fire('load')
        await pending

        expect(FakeXhr.last?.opened).toEqual(['POST', '/media', true])
        expect(FakeXhr.last?.headers['X-CSRF-TOKEN']).toBe('abc')
        expect(FakeXhr.last?.sent).toBeInstanceOf(FormData)
    })

    it('forwards what has gone up so far', async () => {
        install()

        const seen: Array<[number, number]> = []
        const pending = xhrTransport(request({ onProgress: (loaded, total) => seen.push([loaded, total]) }))

        FakeXhr.last?.fire('upload:progress', { loaded: 512, total: 2048 })
        FakeXhr.last?.fire('load')
        await pending

        expect(seen).toEqual([[512, 2048]])
    })

    /**
     * ⚠️ AN UNKNOWN TOTAL IS REPORTED AS ZERO, NOT AS THE BYTES SENT. A browser that cannot
     * compute the length sends `total: 0` alongside a growing `loaded`; passing it through would
     * make the bar read as complete from the first event, then stay there.
     */
    it('reports an unknown total as unknown', async () => {
        install()

        const seen: Array<[number, number]> = []
        const pending = xhrTransport(request({ onProgress: (loaded, total) => seen.push([loaded, total]) }))

        FakeXhr.last?.fire('upload:progress', { loaded: 512, total: 512, lengthComputable: false })
        FakeXhr.last?.fire('load')
        await pending

        expect(seen).toEqual([[512, 0]])
    })

    it('resolves with the status and the parsed body', async () => {
        install()

        const pending = xhrTransport(request())

        const xhr = FakeXhr.last

        if (xhr) {
            xhr.status = 201
            xhr.responseText = '{"data":[{"id":"m1"}],"errors":[]}'
            xhr.fire('load')
        }

        await expect(pending).resolves.toMatchObject({ status: 201 })
    })

    /**
     * ⚠️ A BODY THAT IS NOT JSON STILL CARRIES A STATUS, and the status is what the queue reads
     * to decide. A proxy answering HTML on a 413 must not become a thrown parse error: the size
     * refusal would be reported as a broken client.
     */
    it('keeps the status when the body is not JSON', async () => {
        install()

        const pending = xhrTransport(request())

        const xhr = FakeXhr.last

        if (xhr) {
            xhr.status = 413
            xhr.responseText = '<html>Request Entity Too Large</html>'
            xhr.fire('load')
        }

        await expect(pending).resolves.toEqual({ status: 413, body: null })
    })

    it('rejects when the request never reaches the server', async () => {
        install()

        const pending = xhrTransport(request())

        FakeXhr.last?.fire('error')

        await expect(pending).rejects.toThrow(/could not reach/)
    })

    /** ⚠️ AN ABORT IS NOT A FAILURE — the queue tells them apart by the name of the error. */
    it('aborts the request when the caller withdraws, and says so by name', async () => {
        install()

        const controller = new AbortController()
        const pending = xhrTransport(request({ signal: controller.signal }))

        controller.abort()

        expect(FakeXhr.last?.aborted).toBe(true)
        await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
    })

    /**
     * ⚠️ AND OUTSIDE A BROWSER IT SAYS WHAT TO DO. Server-side rendering, a test runner, a
     * worker: `XMLHttpRequest is not defined` names the missing object and not the way out,
     * which is to pass a transport.
     */
    it('explains itself where there is no XMLHttpRequest', async () => {
        await expect(xhrTransport(request())).rejects.toThrow(/Pass a transport of your own/)
    })
})
