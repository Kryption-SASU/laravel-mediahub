import { computed, ref, shallowRef } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import { MediaHubError } from '../client'
import type { BrowsePage, BrowseQuery, Folder, MediaHubClient, MediaSort, MediaType } from '../client'
import { resolveMediaHub } from './context'

export interface UseMediaBrowser {
    page: Ref<BrowsePage | null>
    query: Ref<BrowseQuery>
    loading: Ref<boolean>
    error: Ref<MediaHubError | null>

    folder: ComputedRef<Folder | null>
    breadcrumbs: ComputedRef<Folder[]>

    refresh(): Promise<void>
    open(folder: Folder | string | null): Promise<void>
    search(term: string | null): Promise<void>
    filterByType(types: readonly MediaType[]): Promise<void>
    sortBy(sort: MediaSort, direction?: 'asc' | 'desc'): Promise<void>
    showTrashed(trashed: boolean): Promise<void>
    goToPage(page: number): Promise<void>
}

/**
 * BROWSING, AS STATE.
 *
 * ⚠️ CHANGING A FILTER RETURNS TO PAGE ONE. Keeping the page across a filter change lands the
 * person on page seven of a result that has three — an empty screen, from a search that found
 * plenty.
 */
export function useMediaBrowser(
    client?: MediaHubClient,
    initial: BrowseQuery = {},
): UseMediaBrowser {
    const api = resolveMediaHub(client)

    const page = shallowRef<BrowsePage | null>(null)
    const query = ref<BrowseQuery>({ ...initial })
    const loading = ref(false)
    const error = ref<MediaHubError | null>(null)

    /**
     * ⚠️ ONLY THE LATEST ANSWER IS KEPT. Two searches typed quickly race, and the slower request
     * is not always the older one: without this counter, the results of "fac" can land after
     * those of "facture" and overwrite them. The screen then shows results for something the
     * person has already finished typing.
     */
    let latest = 0

    async function refresh(): Promise<void> {
        const ticket = ++latest

        loading.value = true
        error.value = null

        try {
            const answer = await api.browse(query.value)

            if (ticket === latest) {
                page.value = answer
            }
        } catch (failure) {
            if (ticket === latest) {
                error.value =
                    failure instanceof MediaHubError
                        ? failure
                        : new MediaHubError(0, null, 'The library could not be reached.')
            }
        } finally {
            if (ticket === latest) {
                loading.value = false
            }
        }
    }

    async function apply(changes: BrowseQuery, resetPage = true): Promise<void> {
        query.value = { ...query.value, ...changes, ...(resetPage ? { page: 1 } : {}) }

        await refresh()
    }

    return {
        page,
        query,
        loading,
        error,

        folder: computed(() => page.value?.folder ?? null),
        breadcrumbs: computed(() => page.value?.breadcrumbs ?? []),

        refresh,

        open(target: Folder | string | null): Promise<void> {
            const id = target === null ? null : typeof target === 'string' ? target : target.id

            return apply({ folder: id })
        },

        search(term: string | null): Promise<void> {
            return apply({ search: term === '' ? null : term })
        },

        filterByType(types: readonly MediaType[]): Promise<void> {
            return apply({ types })
        },

        sortBy(sort: MediaSort, direction: 'asc' | 'desc' = 'desc'): Promise<void> {
            return apply({ sort, direction })
        },

        showTrashed(trashed: boolean): Promise<void> {
            return apply({ trashed })
        },

        /** ⚠️ THE ONLY CHANGE THAT DOES NOT RESET THE PAGE — it is the page. */
        goToPage(target: number): Promise<void> {
            return apply({ page: target }, false)
        },
    }
}
