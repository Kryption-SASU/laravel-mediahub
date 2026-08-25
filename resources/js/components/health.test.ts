import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import type { HealthReport } from '../client'
import { MediaHubError } from '../client'
import { fakeClient, folder, media } from '../vue/fake.test-utils'
import MhHealthReport from './MhHealthReport.vue'
import MhMediaLibrary from './MhMediaLibrary.vue'

async function settle(): Promise<void> {
    for (let turn = 0; turn < 8; turn++) {
        await Promise.resolve()
    }

    await nextTick()
}

const failing: HealthReport = {
    ok: false,
    checks: [
        {
            id: 'extensions.zip',
            level: 'ok',
            title: 'The zip extension',
            detail: 'Loaded.',
            recommendation: null,
        },
        {
            id: 'uploads.post_max_size',
            level: 'error',
            title: 'PHP request size limit (post_max_size)',
            detail: 'PHP refuses requests above 8M.',
            recommendation: 'Set post_max_size to at least 200M.',
        },
        {
            id: 'archives.capacity',
            level: 'warning',
            title: 'Archive size this machine can finish sending',
            detail: 'The configuration allows 8G.',
            recommendation: 'Set mediahub.archives.time_budget.',
        },
    ],
}

function report(props: Record<string, unknown> = {}) {
    const api = fakeClient()

    const wrapper = mount(MhHealthReport, {
        props: { open: true, client: api, ...props },
        attachTo: document.body,
    })

    return { wrapper, api }
}

describe('the health report', () => {
    /**
     * ⚠️ ASKED FOR ON A CLICK, NEVER ON A MOUNT. Reading `php.ini` and probing extensions every
     * time somebody opens a media library is work nobody asked for, on a screen that is open all
     * day — and it would happen even for the hosts that never turn the report on.
     */
    it('asks the server for nothing until it is told to', async () => {
        const { api } = report()

        await settle()

        expect(api.calls.filter((call) => call.method === 'diagnostics')).toHaveLength(0)
    })

    it('asks once it is', async () => {
        const { wrapper, api } = report()

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(api.calls.filter((call) => call.method === 'diagnostics')).toHaveLength(1)
    })

    /**
     * ⚠️ THE SENTENCES COME FROM THE SERVER, WHOLE. They name directives, measured values and a
     * value to set, and only the server can read any of that; a screen that turned a key into a
     * sentence would be inventing the numbers on it.
     */
    it('shows what the server said, including what to change', async () => {
        const { wrapper, api } = report()

        api.answerHealth(failing)

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(wrapper.text()).toContain('PHP refuses requests above 8M.')
        expect(wrapper.text()).toContain('Set post_max_size to at least 200M.')
    })

    /**
     * ⚠️ WHAT IS FAILING COMES FIRST, and what is fine is kept rather than dropped. A report that
     * only listed problems would be indistinguishable from a report that failed to run — and the
     * line saying a limit was checked and is sound is the one that stops somebody changing it
     * for no reason.
     */
    it('puts what is failing at the top and keeps what is not', async () => {
        const { wrapper, api } = report()

        api.answerHealth(failing)

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        const shown = wrapper.findAll('li').map((one) => one.text())

        expect(shown[0]).toContain('post_max_size')
        expect(shown[1]).toContain('Archive size')
        expect(shown[2]).toContain('zip extension')
    })

    /**
     * ⚠️ THE LEVEL IS A WORD AS WELL AS A COLOUR. Colour alone is unreadable to a tenth of the
     * people looking at it, and to everybody who prints the page.
     */
    it('names each level rather than only colouring it', async () => {
        const { wrapper, api } = report()

        api.answerHealth(failing)

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(wrapper.text()).toContain('Failing')
        expect(wrapper.text()).toContain('Worth a look')
    })

    /** ⚠️ THE HEADLINE IS A COUNT. "Two things to look at" is what somebody acts on; a tick they
     * have to interpret is not. */
    it('counts what is worth looking at', async () => {
        const { wrapper, api } = report()

        api.answerHealth(failing)

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(wrapper.text()).toContain('2 things to look at')
    })

    it('says so plainly when there is nothing to do', async () => {
        const { wrapper, api } = report()

        api.answerHealth({ ok: true, checks: [failing.checks[0]!] })

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(wrapper.text()).toContain('in order')
    })

    /**
     * ⚠️ A REFUSAL IS NOT A CLEAN BILL OF HEALTH. The route does not exist when the host has not
     * turned the report on, so the answer is a 404 — and showing an empty, contented report for
     * it would certify a machine nobody ever looked at.
     */
    it('does not report health it could not measure', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(404, null, 'Not found.'))

        const wrapper = mount(MhHealthReport, {
            props: { open: true, client: api },
            attachTo: document.body,
        })

        await (wrapper.vm as unknown as { run(): Promise<void> }).run()
        await settle()

        expect(wrapper.text()).not.toContain('in order')
        expect(wrapper.text()).toContain('Not found.')
    })
})

describe('the button that opens it', () => {
    async function library(props: Record<string, unknown> = {}) {
        const api = fakeClient()
        api.answerBrowse({ media: [media('m1')], folders: [folder('f1')] })

        const wrapper = mount(MhMediaLibrary, {
            props: { client: api, ...props },
            attachTo: document.body,
        })

        await settle()

        return { wrapper, api }
    }

    function opener(wrapper: ReturnType<typeof mount>) {
        return wrapper
            .findAll('button')
            .filter((one) => one.attributes('aria-label') === 'Health report')[0]
    }

    /**
     * ⚠️ THE FLAG IS THE SERVER'S, AND THE BUTTON FOLLOWS IT. The route the report lives on is
     * not registered unless the same setting is on, so a button drawn on a hunch would be one
     * that answers 404 — and it would be offered to everybody who can look at a photograph.
     */
    it('is absent unless the host asked for it', async () => {
        const { wrapper } = await library()

        expect(opener(wrapper)).toBeUndefined()
    })

    it('is there when they did', async () => {
        const { wrapper } = await library({ diagnostics: true })

        expect(opener(wrapper)).toBeDefined()
    })

    /** ⚠️ AND IT CARRIES A NAME DESPITE HAVING NO TEXT: an icon-only control is announced as
     * "button" to anybody not looking at it. */
    it('runs the report when it is pressed', async () => {
        const { wrapper, api } = await library({ diagnostics: true })

        expect(api.calls.filter((call) => call.method === 'diagnostics')).toHaveLength(0)

        await opener(wrapper)?.trigger('click')
        await settle()

        expect(api.calls.filter((call) => call.method === 'diagnostics')).toHaveLength(1)
        expect(wrapper.findComponent(MhHealthReport).props('open')).toBe(true)
    })
})
