/*
 * ⚠️ THE STYLESHEET IS IMPORTED HERE SO THAT ONE BUILD PRODUCES BOTH FILES. Building them
 * separately means two commands, and the day somebody runs one of the two the bundle and
 * its styling stop matching — silently, since both files exist and both are current-looking.
 */
import '../css/standalone.css'

import { createApp } from 'vue'
import { createMediaHubClient } from './client'
import { createMediaHub, MhMediaLibrary, MhMediaInput, MhMediaGallery } from './components'
import type { MhThemeOverride } from './components'

/**
 * THE ENTRY POINT FOR AN APPLICATION THAT DOES NOT BUILD JAVASCRIPT.
 *
 * ⚠️ MOUNTING IS DECLARATIVE, AND THERE IS NO INLINE SCRIPT ANYWHERE. Configuration is read from
 * a `<script type="application/json">` beside the element. An inline script would be blocked by
 * any content security policy worth having, and the host would meet that as a blank area with a
 * console error naming a directive rather than a component.
 *
 * ⚠️ AND IT MOUNTS WHAT IS ALREADY IN THE PAGE, once. A bundle that mounts on an interval, or on
 * every mutation, turns a media library into a thing that fights the host's own framework for
 * control of the same nodes.
 */

interface MountOptions {
    baseUrl?: string
    locale?: string
    theme?: MhThemeOverride
    csrfToken?: string
    /** `library` (the default), `input` or `gallery`. */
    as?: string
    name?: string
    value?: string | string[] | null
}

const COMPONENTS: Record<string, unknown> = {
    library: MhMediaLibrary,
    input: MhMediaInput,
    gallery: MhMediaGallery,
}

/**
 * ⚠️ READ FROM A SIBLING SCRIPT TAG, NOT FROM A DATA ATTRIBUTE. A theme or a list of identifiers
 * does not survive being squeezed into an attribute: quoting breaks it, and the failure is a
 * parse error on somebody's production page rather than here.
 */
function optionsFor(element: Element): MountOptions {
    const source = element.querySelector('script[type="application/json"]')

    if (!source?.textContent) {
        return {}
    }

    try {
        return JSON.parse(source.textContent) as MountOptions
    } catch {
        /*
         * ⚠️ SAID OUT LOUD, AND THE COMPONENT STILL MOUNTS ON ITS DEFAULTS. Swallowing this
         * leaves a screen that works but ignores every setting, which is far harder to diagnose
         * than one that never appeared.
         */
        console.error('[mediahub] the configuration beside this element is not valid JSON.')

        return {}
    }
}

function mount(element: Element): void {
    const options = optionsFor(element)

    const client = createMediaHubClient({
        baseUrl: options.baseUrl ?? '/media',
        csrfToken: () =>
            options.csrfToken ??
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ??
            null,
    })

    const component = COMPONENTS[options.as ?? 'library']

    if (!component) {
        console.error(`[mediahub] there is no component called "${options.as ?? ''}".`)

        return
    }

    const app = createApp(component as never, {
        name: options.name,
        modelValue: options.value ?? (options.as === 'gallery' ? [] : null),
    })

    app.use(createMediaHub({ client, locale: options.locale, theme: options.theme }))
    app.mount(element)
}

export function mountAll(root: ParentNode = document): void {
    for (const element of root.querySelectorAll('[data-mediahub]')) {
        mount(element)
    }
}

/*
 * ⚠️ ON `DOMContentLoaded`, OR STRAIGHT AWAY IF IT HAS PASSED. A bundle loaded with `defer` runs
 * after the event has fired: waiting for it would mean waiting forever, and the page would carry
 * an empty box with nothing in the console.
 */
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mountAll())
    } else {
        mountAll()
    }
}

export { createMediaHubClient, createMediaHub, MhMediaLibrary, MhMediaInput, MhMediaGallery }
