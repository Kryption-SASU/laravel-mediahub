import { computed, ref } from 'vue'
import type { App } from 'vue'
import type { MediaHubClient } from '../client'
import { createTranslator, mediaTextKey } from '../i18n/context'
import type { MhTranslator } from '../i18n/context'
import { mediaThemeKey } from '../theme/context'
import { defaultTheme } from '../theme/defaults'
import { mergeTheme } from '../theme/merge'
import type { MhThemeOverride } from '../theme/types'
import { mediaHubKey } from '../vue/context'

export interface MediaHubOptions {
    client: MediaHubClient
    theme?: MhThemeOverride
    /** A shipped language (`en`, `fr`), or a translator of your own. */
    locale?: string
    text?: MhTranslator
}

/**
 * ⚠️ DECLARED BY ITS SHAPE, NOT BY EXTENDING Vue's `Plugin`. That name is a union of a function
 * and an object, and an interface cannot extend a union — while an object carrying `install` is
 * precisely what `app.use()` accepts, so nothing is lost by describing it directly.
 */
export interface MediaHubPlugin {
    install(app: App): void

    /**
     * Change the skin after installation.
     *
     * ⚠️ THIS IS WHAT MAKES A RUNTIME THEME SWITCH POSSIBLE AT ALL. `app.provide()` takes a value
     * once and for all; a host offering a dark mode, or a per-tenant palette, would otherwise
     * have to recreate the application to change a colour.
     */
    setTheme(theme: MhThemeOverride | undefined): void

    /**
     * Change the language after installation.
     *
     * ⚠️ FOR THE SAME REASON AS THE THEME: `app.provide()` takes a value once and for all, and a
     * host offering a language switcher would otherwise have to rebuild the application to
     * change a word.
     */
    setLocale(locale: string): void
}

/**
 * THE ORDINARY WAY IN: one line at the application, and every component below is served.
 *
 * ⚠️ IT PROVIDES THE SAME TWO KEYS AS `MhProvider`, deliberately. Two mechanisms that looked
 * alike but fed different injections would produce a component tree that works at the root and
 * fails three levels down, for reasons nothing on screen explains.
 */
export function createMediaHub(options: MediaHubOptions): MediaHubPlugin {
    const override = ref<MhThemeOverride | undefined>(options.theme)
    const theme = computed(() => mergeTheme(defaultTheme, override.value))

    const locale = ref<string>(options.locale ?? 'en')
    const text: MhTranslator = options.text ?? createTranslator(() => locale.value)

    return {
        install(app: App): void {
            app.provide(mediaHubKey, options.client)
            app.provide(mediaThemeKey, theme)
            app.provide(mediaTextKey, text)
        },

        setTheme(next: MhThemeOverride | undefined): void {
            override.value = next
        },

        setLocale(next: string): void {
            locale.value = next
        },
    }
}
