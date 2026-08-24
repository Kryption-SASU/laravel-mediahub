import { effectScope } from 'vue'
import { describe, expect, it } from 'vitest'
import type { UploadTransport, UploadTransportRequest, UploadTransportResponse } from '../client'
import { fakeClient, folder, media } from './fake.test-utils'
import type { FakeClient } from './fake.test-utils'
import { useMediaActions } from './useMediaActions'
import { useMediaBrowser } from './useMediaBrowser'
import { useMediaPicker } from './useMediaPicker'
import { useSelection } from './useSelection'
import { useUpload } from './useUpload'

/**
 * THE ACCEPTANCE CRITERION OF THIS LAYER, WRITTEN AS A TEST.
 *
 * ⚠️ THE DESIGN NOTES SET IT IN ONE SENTENCE: *writing an entirely different interface must be a
 * day's work*. That is not something a unit test can measure — but its opposite is. If a whole
 * screen cannot be assembled from these composables without reaching past them, the layer is cut
 * wrong, and the right moment to find out is now rather than once components exist on top.
 *
 * ⚠️ SO THIS FILE BUILDS A SCREEN WITH NO MARKUP. Everything a media library does — browse, walk
 * into a folder, select, act on a batch, upload, pick — goes through layer 2 and nothing else.
 * Not one import from `../client`, apart from the types a caller would use anyway.
 */

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
        settle(index: number, body: unknown): void {
            pending[index]?.({ status: 201, body })
        },
    }
}

async function settleAll(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }
}

/**
 * A COMPLETE SCREEN, MINUS THE PIXELS. This is what a host would write — and the point is that
 * it is short, and that nothing in it works around the layer.
 */
function headlessLibrary(api: FakeClient, transport: UploadTransport) {
    const browser = useMediaBrowser(api)
    const selection = useSelection()
    const actions = useMediaActions(api)
    const upload = useUpload(api, { transport, concurrency: 2 })
    const picker = useMediaPicker()

    return {
        browser,
        selection,
        actions,
        upload,
        picker,

        /** "Delete what is selected", the way a toolbar button would do it. */
        async trashSelection(): Promise<number> {
            const affected = await actions.trash(selection.asSelection())

            selection.clear()
            await browser.refresh()

            return affected.count
        },

        /** "Drop files here", the way a dropzone would. */
        async dropInCurrentFolder(files: File[]): Promise<void> {
            upload.add(files, { folder: browser.folder.value?.id ?? null })
            await settleAll()
        },
    }
}

describe('a whole screen, built only from composables', () => {
    it('browses, walks into a folder, selects, acts and uploads', async () => {
        const api = fakeClient()
        const bus = controllable()

        const scope = effectScope()
        const screen = scope.run(() => headlessLibrary(api, bus.transport))!

        // ── it opens on the root, and shows what is there ────────────────────
        api.answerBrowse({ folders: [folder('invoices')], media: [media('m1')] })
        await screen.browser.refresh()

        expect(screen.browser.page.value?.folders).toHaveLength(1)
        expect(screen.browser.folder.value).toBeNull()

        // ── somebody clicks a folder ─────────────────────────────────────────
        api.answerBrowse({
            folder: folder('invoices'),
            breadcrumbs: [folder('invoices')],
            media: [media('m2'), media('m3')],
        })
        await screen.browser.open(folder('invoices'))

        expect(screen.browser.folder.value?.id).toBe('invoices')
        expect(screen.browser.breadcrumbs.value).toHaveLength(1)

        // ── they select two files and delete them ────────────────────────────
        screen.selection.toggle('media', 'm2')
        screen.selection.toggle('media', 'm3')

        expect(screen.selection.count.value).toBe(2)

        const affected = await screen.trashSelection()

        expect(affected).toBe(1)
        expect(screen.selection.empty.value).toBe(true)
        expect(api.calls.some((call) => call.method === 'trash')).toBe(true)

        // ── then they drop a file into the folder they are looking at ────────
        await screen.dropInCurrentFolder([file('new.png')])
        bus.settle(0, { data: [{ id: 'm4' }], errors: [] })
        await settleAll()

        expect(screen.upload.stored.value.map((item) => item.id)).toEqual(['m4'])

        scope.stop()
    })

    /**
     * ⚠️ THE UPLOAD HAS TO LAND WHERE THE PERSON IS LOOKING. A dropzone that ignores the open
     * folder puts everything at the root, and the file appears to vanish — it is simply
     * somewhere else, which is worse than an error.
     */
    it('uploads into the folder currently open', async () => {
        const api = fakeClient()
        const bus = controllable()

        const scope = effectScope()
        const screen = scope.run(() => headlessLibrary(api, bus.transport))!

        api.answerBrowse({ folder: folder('invoices') })
        await screen.browser.open('invoices')

        await screen.dropInCurrentFolder([file()])

        expect(screen.upload.items.value[0]?.folder).toBe('invoices')

        scope.stop()
    })

    /** A picker is the same screen with a promise on the end of it. */
    it('hands a chosen media back to whoever asked', async () => {
        const api = fakeClient()
        const bus = controllable()

        const scope = effectScope()
        const screen = scope.run(() => headlessLibrary(api, bus.transport))!

        api.answerBrowse({ media: [media('m1'), media('m2')] })

        const chosen = screen.picker.pick({ multiple: true, types: ['image'] })

        await screen.browser.refresh()
        screen.picker.choose(screen.browser.page.value?.media ?? [])

        await expect(chosen).resolves.toHaveLength(2)

        scope.stop()
    })
})
