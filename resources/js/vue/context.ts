import { inject, provide } from 'vue'
import type { InjectionKey } from 'vue'
import type { MediaHubClient } from '../client'

export const mediaHubKey: InjectionKey<MediaHubClient> = Symbol('mediahub.client')

export function provideMediaHub(client: MediaHubClient): void {
    provide(mediaHubKey, client)
}

/**
 * THE CLIENT, EXPLICITLY OR FROM THE TREE.
 *
 * ⚠️ EVERY COMPOSABLE TAKES THE CLIENT AS AN OPTIONAL ARGUMENT, and that is what makes them
 * testable without mounting anything. Injection alone would force every test to build a
 * component just to reach a function that has nothing to do with rendering.
 *
 * ⚠️ AND `inject()` IS ONLY CALLED WHEN THERE IS NOTHING TO USE. Calling it outside `setup()`
 * warns and returns nothing; asking for it when the caller already handed us a client would fill
 * the console with warnings on every test.
 */
export function resolveMediaHub(client?: MediaHubClient): MediaHubClient {
    if (client !== undefined) {
        return client
    }

    /*
     * ⚠️ OUTSIDE A COMPONENT, `inject()` RETURNS `undefined` — NOT THE DEFAULT IT WAS GIVEN. Vue
     * only consults the default when there is an instance to resolve against; with none it warns
     * and falls through. A guard written against `null` alone therefore never fires, and hands
     * back `undefined` as if it were a client — after which the first call fails somewhere else
     * entirely, with a message about a property of undefined.
     */
    const injected = inject(mediaHubKey, null) as MediaHubClient | null | undefined

    if (injected === null || injected === undefined) {
        throw new Error(
            'No MediaHub client. Pass one to the composable, or call provideMediaHub() higher in the tree.',
        )
    }

    return injected
}
