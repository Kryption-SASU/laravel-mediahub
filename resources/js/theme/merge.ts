import type { MhComponentOverride, MhTheme, MhThemeOverride } from './types'

/**
 * WHERE "THE HOST WINS" STOPS BEING A SENTENCE.
 *
 * ⚠️ THE PRECEDENCE IS: DEFAULT, THEN THE GLOBAL THEME, THEN THE `ui` PROP — the nearest one
 * wins. Anything else would make a local adjustment unpredictable, and a component that cannot
 * be adjusted locally gets copied instead.
 */
export function mergeTheme(base: MhTheme, ...overrides: ReadonlyArray<MhThemeOverride | undefined>): MhTheme {
    const merged: MhTheme = {}

    for (const [component, slots] of Object.entries(base)) {
        merged[component] = { ...slots }
    }

    for (const override of overrides) {
        if (!override) {
            continue
        }

        for (const [component, slots] of Object.entries(override)) {
            merged[component] = mergeComponent(merged[component] ?? {}, slots)
        }
    }

    return merged
}

/**
 * ⚠️ A COMPONENT THE DEFAULT THEME DOES NOT KNOW IS STILL MERGED IN. Refusing it would mean a
 * host cannot theme a component of their own written against this table — and the table is the
 * only way we offer them to do it consistently.
 */
function mergeComponent(base: MhTheme[string], override: MhComponentOverride): MhTheme[string] {
    const merged: MhTheme[string] = { ...base }

    for (const [slot, style] of Object.entries(override)) {
        /*
         * ⚠️ `layout` IS TAKEN FROM THE BASE, ALWAYS. The type already refuses it on the way in;
         * this is what holds when the caller is JavaScript, or JSON read from a configuration
         * file, where no type ever ran.
         */
        merged[slot] = {
            layout: base[slot]?.layout,
            class: Object.hasOwn(style, 'class') ? style.class : base[slot]?.class,
        }
    }

    return merged
}

/**
 * ⚠️ `hasOwn` RATHER THAN A TRUTHINESS TEST, and it is not pedantry: an empty string means "this
 * place has no skin", which is a legitimate thing for a host to ask for. Read as "absent", the
 * default colours would come back and the host would be unable to remove them at all.
 */
export function classesOf(theme: MhTheme, component: string, slot: string): string {
    const style = theme[component]?.[slot]

    if (!style) {
        return ''
    }

    return [style.layout, style.class].filter((part) => part !== undefined && part !== '').join(' ')
}
