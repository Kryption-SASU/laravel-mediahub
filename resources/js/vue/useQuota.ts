import { computed, ref } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import { MediaHubError } from '../client'
import type { MediaHubClient, Quota } from '../client'
import { resolveMediaHub } from './context'

export interface UseQuota {
    quota: Ref<Quota | null>
    loading: Ref<boolean>
    error: Ref<MediaHubError | null>

    /** Between 0 and 1, or `null` when there is no limit. Never a number on an unlimited quota. */
    ratio: ComputedRef<number | null>

    refresh(): Promise<void>
}

/**
 * HOW MUCH ROOM IS LEFT.
 *
 * ⚠️ AN UNLIMITED QUOTA HAS NO PERCENTAGE, and returning zero would be worse than returning
 * nothing: a gauge at 0 % reads as "empty" and one at 100 % as "full", while the truth is that
 * the question does not apply. `null` forces the screen to decide what to show, which is the
 * only place that decision belongs.
 */
export function useQuota(client?: MediaHubClient): UseQuota {
    const api = resolveMediaHub(client)

    const quota = ref<Quota | null>(null)
    const loading = ref(false)
    const error = ref<MediaHubError | null>(null)

    return {
        quota,
        loading,
        error,

        ratio: computed(() => {
            const current = quota.value

            if (current === null || current.limit === null || current.limit <= 0) {
                return null
            }

            return Math.min(1, current.used / current.limit)
        }),

        async refresh(): Promise<void> {
            loading.value = true
            error.value = null

            try {
                quota.value = await api.quota()
            } catch (failure) {
                error.value =
                    failure instanceof MediaHubError
                        ? failure
                        : new MediaHubError(0, null, 'The quota could not be read.')
            } finally {
                loading.value = false
            }
        },
    }
}
