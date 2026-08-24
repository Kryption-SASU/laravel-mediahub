import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { useNativeDialog } from './useNativeDialog'

/**
 * THE PATH TAKEN WHERE `<dialog>` HAS NO METHODS.
 *
 * ⚠️ THIS BRANCH EXISTS BECAUSE CALLING THEM BLIND THROWS DURING A RENDER, and takes the whole
 * screen down to avoid showing a prompt. Some embedded browsers and some test environments give
 * you the element without `showModal`.
 *
 * ⚠️ AND IT CANNOT BE REACHED THROUGH A COMPONENT HERE, because this environment does implement
 * them. A defensive branch nothing exercises is a branch that stops working without anyone
 * finding out — so it is exercised directly, with an element that has been stripped of them.
 */
function stripped() {
    const element = document.createElement('div') as unknown as HTMLDialogElement

    Object.defineProperty(element, 'open', {
        get: () => element.hasAttribute('open'),
    })

    return element
}

function harness(target: HTMLDialogElement) {
    const isOpen = ref(false)

    const wrapper = mount(
        defineComponent({
            setup() {
                const reference = ref<HTMLDialogElement | null>(target)

                useNativeDialog(reference, isOpen)

                return () => h('span')
            },
        }),
    )

    return { wrapper, isOpen }
}

describe('showing a dialog that cannot show itself', () => {
    it('falls back to the attribute rather than throwing', async () => {
        const element = stripped()
        const { isOpen } = harness(element)

        isOpen.value = true
        await nextTick()

        expect(element.hasAttribute('open')).toBe(true)
    })

    it('and closes the same way', async () => {
        const element = stripped()
        const { isOpen } = harness(element)

        isOpen.value = true
        await nextTick()

        isOpen.value = false
        await nextTick()

        expect(element.hasAttribute('open')).toBe(false)
    })

    /** ⚠️ AND IT DOES NOTHING AT ALL BEFORE THE ELEMENT EXISTS — which is every first render. */
    it('waits for an element to exist', async () => {
        const reference = ref<HTMLDialogElement | null>(null)
        const isOpen = ref(true)

        mount(
            defineComponent({
                setup() {
                    useNativeDialog(reference, isOpen)

                    return () => h('span')
                },
            }),
        )

        await nextTick()

        expect(reference.value).toBeNull()
    })
})
