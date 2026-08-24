import { computed, inject, provide, toValue } from 'vue'
import type { ComputedRef, InjectionKey, MaybeRefOrGetter } from 'vue'
import { defaultTheme } from './defaults'
import { classesOf, mergeTheme } from './merge'
import type { MhClasses, MhComponentOverride, MhTheme, MhThemeOverride } from './types'

export const mediaThemeKey: InjectionKey<ComputedRef<MhTheme>> = Symbol('mediahub.theme')

/**
 * ⚠️ THE THEME IS PROVIDED AS A COMPUTED, NOT AS A FROZEN OBJECT. A host switching to dark mode,
 * or letting somebody pick a brand at runtime, changes the override; a resolved snapshot would
 * leave every mounted component showing the previous skin until it happened to re-render.
 */
export function provideMediaTheme(override?: MaybeRefOrGetter<MhThemeOverride | undefined>): void {
    provide(
        mediaThemeKey,
        computed(() => mergeTheme(defaultTheme, toValue(override))),
    )
}

/**
 * THE CLASSES FOR ONE COMPONENT, LOCAL ADJUSTMENT INCLUDED.
 *
 * ⚠️ IT WORKS WITH NO PROVIDER AT ALL, and that is deliberate. A component dropped into a page
 * to try it out must render styled; making the theme mandatory would mean the first thing anyone
 * sees is an exception rather than a screen.
 */
export function useMediaTheme(
    component: string,
    ui?: MaybeRefOrGetter<MhComponentOverride | undefined>,
): MhClasses {
    const provided = inject(mediaThemeKey, null)

    const resolved = computed(() => {
        const base = provided?.value ?? mergeTheme(defaultTheme)
        const local = toValue(ui)

        return local ? mergeTheme(base, { [component]: local }) : base
    })

    return (slot: string): string => classesOf(resolved.value, component, slot)
}
