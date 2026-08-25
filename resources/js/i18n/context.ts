import { computed, inject, provide, toValue } from 'vue'
import type { InjectionKey, MaybeRefOrGetter } from 'vue'
import { MH_DEFAULT_LOCALE, MH_LOCALES } from './messages'
import type { MhLocale, MhMessages } from './messages'

/**
 * ONE FUNCTION, AND A HOST CAN REPLACE ALL OF IT.
 *
 * ⚠️ THE TRANSLATOR IS INJECTED RATHER THAN BUILT IN. An application that already runs
 * `vue-i18n`, or any other engine, plugs it in with one line and keeps a single catalogue for
 * the whole product. A package insisting on its own would leave them maintaining two, and the
 * second one always falls behind.
 */
export type MhTranslator = (
    key: string,
    replacements?: Readonly<Record<string, string | number>>,
    count?: number,
) => string

export const mediaTextKey: InjectionKey<MhTranslator> = Symbol('mediahub.text')

/**
 * THE ONE THIS PACKAGE SHIPS.
 *
 * ⚠️ AN UNKNOWN KEY COMES BACK AS ITSELF, not as an empty string. A blank button says nothing at
 * all and looks like a rendering fault; `picker.choose` on a button says exactly what is missing
 * and where — which is what somebody adding a language needs to read.
 */
export function createTranslator(locale: MaybeRefOrGetter<string | MhLocale | undefined>): MhTranslator {
    return (key, replacements, count) => {
        const asked = toValue(locale)

        const resolved: MhLocale =
            typeof asked === 'object' && asked !== null
                ? asked
                : (MH_LOCALES[(asked as string | undefined) ?? MH_DEFAULT_LOCALE] ??
                  MH_LOCALES[MH_DEFAULT_LOCALE] ?? { messages: {} as MhMessages, plural: () => 0 })

        const line = resolved.messages[key]

        if (line === undefined) {
            return key
        }

        const forms = line.split('|')
        const chosen =
            count === undefined || forms.length < 2
                ? forms[0]
                : (forms[resolved.plural(count)] ?? forms[0])

        return fill(chosen ?? key, { ...replacements, ...(count === undefined ? {} : { count }) })
    }
}

/**
 * ⚠️ REPLACEMENTS ARE SUBSTITUTED, NOT INTERPOLATED AS CODE. The values come from a server
 * response as often as not — a file name, a folder — and building the sentence any other way
 * would put someone else's text where a template expression is evaluated.
 */
function fill(line: string, replacements: Readonly<Record<string, string | number>>): string {
    return Object.entries(replacements).reduce(
        (carry, [name, value]) => carry.split('{' + name + '}').join(String(value)),
        line,
    )
}

export function provideMediaText(translator: MhTranslator): void {
    provide(mediaTextKey, translator)
}

/**
 * THE LANGUAGE ITSELF, BESIDE THE TRANSLATOR — and it is not the same thing.
 *
 * ⚠️ A DATE IS NOT A TRANSLATABLE STRING. "12 août 2026, 14:03" is built by `Intl` from a
 * language tag, and no catalogue of sentences can produce it: the order of the parts, the name
 * of the month and the shape of the clock all come from the tag. The translator cannot hand one
 * out — a host may have replaced it with `vue-i18n` — so the tag is provided on its own.
 */
export const mediaLocaleKey: InjectionKey<() => string | undefined> = Symbol('mediahub.locale')

export function provideMediaLocale(locale: MaybeRefOrGetter<string | undefined>): void {
    provide(mediaLocaleKey, () => toValue(locale))
}

/**
 * ⚠️ IT ANSWERS `undefined` WHEN NOBODY SAID, and that is a usable answer: `Intl` then takes the
 * runtime's own language. Throwing, or inventing `en`, would both be worse — the first breaks a
 * component dropped onto a page to try it, the second prints American dates in a French
 * application and looks like a bug in the library rather than a missing provider.
 */
export function useMediaLocale(): () => string | undefined {
    const provided = inject(mediaLocaleKey, null)

    return () => provided?.()
}

/**
 * A LANGUAGE TAG `Intl` WILL ACCEPT, OR NOTHING.
 *
 * ⚠️ `Intl` THROWS A `RangeError` ON A TAG IT CANNOT PARSE, and `fr_FR` — the shape PHP and
 * Laravel carry everywhere — is one of them: BCP 47 wants a hyphen. An uncaught throw here takes
 * a whole panel down for the sake of a date, so the underscore is converted rather than
 * discovered, and anything still unparseable falls back to the runtime's own language.
 */
export function intlLocale(tag: string | undefined): string | undefined {
    if (tag === undefined || tag === '') {
        return undefined
    }

    const normalised = tag.replace(/_/g, '-')

    try {
        return Intl.DateTimeFormat.supportedLocalesOf([normalised]).length > 0
            ? normalised
            : undefined
    } catch {
        /* ⚠️ `supportedLocalesOf` ITSELF THROWS on a structurally invalid tag — it is the check,
         * and it is also the thing being checked. Anything it refuses to look at is not a tag. */
        return undefined
    }
}

/**
 * ⚠️ IT WORKS WITH NOTHING PROVIDED, in the package's own default language. A component dropped
 * into a page to try it out must render words rather than an exception — the same reasoning as
 * the theme, and for the same reason: the first thing anybody sees should be a screen.
 */
export function useMediaText(): MhTranslator {
    const provided = inject(mediaTextKey, null)
    const fallback = computed(() => createTranslator(MH_DEFAULT_LOCALE))

    return (key, replacements, count) =>
        (provided ?? fallback.value)(key, replacements, count)
}
