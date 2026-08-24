import { describe, expect, it } from 'vitest'
import * as client from './client'
import * as vue from './vue'

/**
 * WHAT THE PACKAGE PROMISES TO EXPORT.
 *
 * ⚠️ A BARREL IS AN API, NOT PLUMBING. Everything named here is what a host application imports;
 * removing or renaming one entry breaks their build, and nothing else in this suite would say
 * so — every other test imports the modules directly, which is exactly why both barrels sat at
 * zero coverage while the layer was fully exercised.
 *
 * ⚠️ THE LIST IS EXHAUSTIVE ON PURPOSE, and `toEqual` rather than `toContain`. A test that only
 * checks presence lets a symbol be added without a decision — and once it is exported, taking it
 * back is a breaking change. Adding a line here is that decision.
 *
 * ⚠️ TYPES DO NOT APPEAR IN IT, and cannot: `verbatimModuleSyntax` erases them at build time, so
 * a `Media` gone missing is caught by `npm run types`, not here. The two guards are neither
 * interchangeable nor redundant.
 */
describe('the public surface', () => {
    it('is what the core says it is', () => {
        expect(Object.keys(client).sort()).toEqual([
            'MEDIA_TYPES',
            'MediaHubError',
            'createMediaHubClient',
            'createUploadQueue',
            'xhrTransport',
        ])
    })

    it('is what the Vue layer says it is', () => {
        expect(Object.keys(vue).sort()).toEqual([
            'mediaHubKey',
            'provideMediaHub',
            'resolveMediaHub',
            'useFolders',
            'useMediaActions',
            'useMediaBrowser',
            'useMediaPicker',
            'useQuota',
            'useSelection',
            'useUpload',
        ])
    })

    /**
     * ⚠️ THE VALUES ARE THE UNION, AND THE UNION IS THE VALUES. They are declared once and the
     * type is derived from them, so this cannot drift — but a hand-written list added later in a
     * hurry could, and it would drift silently: a runtime check against a stale list rejects a
     * payload the types accept.
     */
    it('ships the media types as data, not only as a type', () => {
        expect(client.MEDIA_TYPES).toEqual(['image', 'video', 'audio', 'document', 'external', 'other'])
    })
})
