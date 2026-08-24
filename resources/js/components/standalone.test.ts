import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountAll } from '../standalone'

/**
 * THE ENTRY POINT FOR AN APPLICATION THAT DOES NOT BUILD JAVASCRIPT.
 *
 * ⚠️ THIS IS THE ONLY CODE PATH THE STANDALONE MODE HAS. Everything else in the package is
 * exercised by hosts that compile the sources; if this file is wrong, the mode that exists so
 * that `composer require` is enough produces a page with an empty box and nothing in the
 * console. It was written before this test existed, and the coverage report is what said so.
 */

function page(html: string): HTMLElement {
    const root = document.createElement('div')

    root.innerHTML = html
    document.body.append(root)

    return root
}

afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
})

describe('mounting from the markup', () => {
    it('mounts what carries the attribute, and nothing else', () => {
        const root = page('<div data-mediahub></div><div id="other"></div>')

        mountAll(root)

        expect(root.querySelector('[data-mediahub]')?.innerHTML).not.toBe('')
        expect(root.querySelector('#other')?.innerHTML).toBe('')
    })

    /**
     * ⚠️ THE CONFIGURATION COMES FROM A SIBLING SCRIPT TAG, NOT FROM A DATA ATTRIBUTE. A theme or
     * a list of identifiers does not survive being squeezed into an attribute — and an inline
     * script would be refused by any content security policy worth having, which the host meets
     * as a blank area and a console message naming a directive rather than a component.
     */
    it('reads the configuration beside the element', () => {
        const root = page(
            '<div data-mediahub><script type="application/json">{"as":"input","name":"avatar_id"}</script></div>',
        )

        mountAll(root)

        expect(root.querySelector('input[type="hidden"]')?.getAttribute('name')).toBe('avatar_id')
    })

    it('mounts the library when nothing says otherwise', () => {
        const root = page('<div data-mediahub></div>')

        mountAll(root)

        expect(root.querySelector('[role="search"]')).not.toBeNull()
    })

    /**
     * ⚠️ SAID OUT LOUD, AND THE COMPONENT STILL MOUNTS. Swallowing a malformed configuration
     * leaves a screen that works and ignores every setting, which is far harder to diagnose than
     * one that never appeared at all.
     */
    it('complains about a configuration it cannot read, and mounts anyway', () => {
        const complained = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const root = page('<div data-mediahub><script type="application/json">{ not json</script></div>')

        mountAll(root)

        expect(complained).toHaveBeenCalledOnce()
        expect(root.querySelector('[data-mediahub]')?.innerHTML).not.toBe('')
    })

    /** ⚠️ AND A COMPONENT NOBODY SHIPS IS NAMED, rather than leaving an empty box to explain. */
    it('names a component it does not have', () => {
        const complained = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const root = page(
            '<div data-mediahub><script type="application/json">{"as":"carousel"}</script></div>',
        )

        mountAll(root)

        expect(complained).toHaveBeenCalledWith(expect.stringContaining('carousel'))
    })

    it('starts a gallery on a list rather than on nothing', () => {
        const root = page(
            '<div data-mediahub><script type="application/json">{"as":"gallery","name":"ids[]"}</script></div>',
        )

        mountAll(root)

        /* An empty gallery renders its own empty wording rather than failing on a null model. */
        expect(root.textContent).toContain('No files chosen')
    })

    it('takes the language it was given', () => {
        const root = page(
            '<div data-mediahub><script type="application/json">{"as":"gallery","locale":"fr"}</script></div>',
        )

        mountAll(root)

        expect(root.textContent).toContain('Aucun fichier choisi')
    })
})
